<?php

namespace MM\Meros\Crm\App\DynamicChoiceSources;

use MM\Meros\Crm\App\Integrations\ExternalModels\SalesforceDynamicObject;
use MM\Meros\Crm\App\Integrations\ExternalModels\SalesforceObject;
use MM\Meros\Services\Contracts\Forms\DynamicChoiceSource;

class SalesforceObjectRecords extends DynamicChoiceSource {
    public string $source = 'salesforce_object_records';

    protected string $label = 'Salesforce Object Records';

    protected string $description = 'Query records from any Salesforce object by API name.';

    protected array $configFields = [
        [
            'key'      => 'object_api_name',
            'label'    => 'Object API Name',
            'type'     => 'text',
            'default'  => 'Contact',
            'helpText' => 'Salesforce object API name, for example Contact, Account, or Lead.',
        ],
        [
            'key'      => 'limit',
            'label'    => 'Dynamic Options Limit',
            'type'     => 'number',
            'default'  => 20,
            'min'      => 1,
            'helpText' => 'Maximum number of record results returned per request.',
        ],
        [
            'key'      => 'advanced_query',
            'label'    => 'Advanced Query',
            'type'     => 'textarea',
            'default'  => '',
            'helpText' => "Optional line-based filters. One clause per line: Field OP Value. Example:\nRecordTypeId = 012xxxxxxxxxxxx\nCreatedDate >= 2024-01-01\nEmail LIKE %@example.com",
        ],
    ];

    /**
     * Resolves the dynamic options for the field based on the request parameters.
     * 
     * @return array<int, array{value:string,text:string}>
     */
    public function resolve(\WP_REST_Request $request): array {
        if (!$this->isAvailable()) {
            return [];
        }

        $objectApiName = $this->normaliseObjectApiName((string) ($request->get_param('object_api_name') ?: ''));

        if ($objectApiName === '') {
            return [];
        }

        // The string entered in the search field, if any.
        $search = trim(sanitize_text_field((string) ($request->get_param('search') ?: '')));
        $searchIsRecordId = $this->isSalesforceRecordId($search);
        
        // The number of record results to return, defaulting to 20 if not specified.
        $limit = max(1, min(200, (int) ($request->get_param('limit') ?: 20)));
        
        // The number of record results to return, defaulting to 20 if not specified.
        $queryLimit = ($search !== '' && !$searchIsRecordId) ? max($limit, min(200, max($limit * 5, 50))) : $limit;
        
        // The field to use as the option value
        $valueField = 'Id';
        
        // The field to use as the option label
        $labelField = 'Name';
        
        // The fields to select from the Salesforce object
        $queryFields = ['Id', 'Name'];
        
        $advancedQuery = trim(sanitize_textarea_field((string) (
            $request->get_param('advanced_query')
            ?: ''
        )));

        $query = SalesforceDynamicObject::forObject($objectApiName, '')
            ->select($queryFields)
            ->limit($queryLimit);

        if ($search !== '') {
            if ($searchIsRecordId) {
                $query->where($valueField, '=', $search);
            } else {
                $query->where($labelField, 'LIKE', '%' . $search . '%');
            }
        }

        if ($advancedQuery !== '') {
            $this->applyAdvancedQuery($query, $advancedQuery);
        }

        $records = $query->get();

        if ($search !== '') {
            $records = array_values(array_filter($records, function ($record) use ($search, $searchIsRecordId, $valueField, $labelField) {
                if (!is_array($record)) {
                    return false;
                }

                if ($searchIsRecordId) {
                    return strcasecmp((string) ($record[$valueField] ?? ''), $search) === 0;
                }

                $haystack = (string) ($record[$labelField] ?? '');

                return $haystack !== '' && stripos($haystack, $search) !== false;
            }));
        }

        return array_slice(array_values(array_filter(array_map(function ($record) use ($valueField, $labelField) {
            if (!is_array($record)) {
                return null;
            }

            $value = trim((string) ($record[$valueField] ?? ''));

            if ($value === '') {
                return null;
            }

            $label = trim((string) ($record[$labelField] ?? ''));

            return [
                'value' => $value,
                'text' => $label !== '' ? $label : $value,
            ];
        }, $records))), 0, $limit);
    }

    /**
     * Returns whether the Salesforce integration is available and enabled.
     *
     * @return boolean
     */
    public function isAvailable(): bool {
        $settings = get_option('meros_framework_settings', []);
        $integrations = is_array($settings['integrations'] ?? null) ? $settings['integrations'] : [];

        $integrationsFeatureEnabled = (bool) ($integrations['enable_integrations'] ?? false);

        if (!$integrationsFeatureEnabled) {
            return false;
        }

        return (bool) ($integrations['salesforce_enable'] ?? false);
    }

    /**
     * Normalises a Salesforce object API name by removing any invalid characters and trimming whitespace.
     *
     * @param string $objectApiName
     *
     * @return string
     */
    private function normaliseObjectApiName(string $objectApiName): string {
        return preg_replace('/[^A-Za-z0-9_]/', '', trim($objectApiName)) ?: '';
    }

    /**
     * Applies an advanced query string to the SalesforceObject query.
     *
     * @param SalesforceObject $query
     * @param string           $advancedQuery
     */
    private function applyAdvancedQuery(SalesforceObject $query, string $advancedQuery): void {
        $lines = preg_split('/\r\n|\r|\n/', $advancedQuery) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_\.]+)\s+(NOT IN|IN|LIKE|>=|<=|!=|=|>|<)\s+(.+)$/i', $line, $matches) !== 1) {
                continue;
            }

            $field    = $this->normaliseFieldName((string) ($matches[1] ?? ''));
            $operator = strtoupper(trim((string) ($matches[2] ?? '')));
            $rawValue = trim((string) ($matches[3] ?? ''));

            if ($field === '' || $rawValue === '' || !in_array($operator, $this->allowedOperators(), true)) {
                continue;
            }

            if (in_array($operator, ['IN', 'NOT IN'], true)) {
                $values = $this->parseListValue($rawValue);

                if ($values !== []) {
                    $query->where($field, $operator, $values);
                }

                continue;
            }

            $query->where($field, $operator, $this->parseScalarValue($rawValue));
        }
    }

    /**
     * Normalises a Salesforce field name by removing any invalid characters and trimming whitespace.
     *
     * @param string $field
     *
     * @return string
     */
    private function normaliseFieldName(string $field): string {
        $field = trim($field);

        if ($field === '') {
            return '';
        }

        return preg_replace('/[^A-Za-z0-9_\.]/', '', $field) ?: '';
    }

    /**
     * Returns the list of allowed operators for advanced queries.
     * 
     * @return array<int, string>
     */
    private function allowedOperators(): array {
        return ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IN', 'NOT IN'];
    }

    /**
     * Parses a comma-separated list of values from a string, handling optional parentheses or brackets.
     * 
     * @return array<int, mixed>
     */
    private function parseListValue(string $value): array {
        $trimmed = trim($value);

        if ((str_starts_with($trimmed, '(') && str_ends_with($trimmed, ')')) || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
            $trimmed = trim(substr($trimmed, 1, -1));
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $trimmed)), fn ($part) => $part !== ''));

        return array_map(fn ($part) => $this->parseScalarValue($part), $parts);
    }

    /**
     * Parses a scalar value from a string, handling quoted strings, booleans, nulls, and numbers.
     * 
     * @param string $value
     * @return mixed
     */
    private function parseScalarValue(string $value): mixed {
        $value = trim($value);

        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return substr($value, 1, -1);
        }

        $upper = strtoupper($value);

        if ($upper === 'NULL') {
            return null;
        }

        if ($upper === 'TRUE') {
            return true;
        }

        if ($upper === 'FALSE') {
            return false;
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    /**
     * Determines whether a search term looks like a Salesforce record ID (15 or 18 alphanumeric chars).
     */
    private function isSalesforceRecordId(string $value): bool {
        $candidate = trim($value);

        if ($candidate === '') {
            return false;
        }

        return preg_match('/^[A-Za-z0-9]{15}(?:[A-Za-z0-9]{3})?$/', $candidate) === 1;
    }
}