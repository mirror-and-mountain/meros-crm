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
            'key' => 'connection',
            'label' => 'Salesforce Connection',
            'type' => 'text',
            'default' => '',
            'helpText' => 'Optional integration connection label. Leave empty for the default active connection.',
        ],
        [
            'key' => 'objectApiName',
            'label' => 'Object API Name',
            'type' => 'text',
            'default' => 'Contact',
            'helpText' => 'Salesforce object API name, for example Contact, Account, or Lead.',
        ],
        [
            'key' => 'fields',
            'label' => 'Fields (comma separated)',
            'type' => 'text',
            'default' => 'Id,Name',
            'helpText' => 'Fields selected in SOQL. Always includes value, label, and search fields.',
        ],
        [
            'key' => 'valueField',
            'label' => 'Value Field',
            'type' => 'text',
            'default' => 'Id',
            'helpText' => 'Field returned as option value.',
        ],
        [
            'key' => 'labelField',
            'label' => 'Label Field',
            'type' => 'text',
            'default' => 'Name',
            'helpText' => 'Field returned as option label text.',
        ],
        [
            'key' => 'searchField',
            'label' => 'Search Field',
            'type' => 'text',
            'default' => 'Name',
            'helpText' => 'Field used when searching typed input.',
        ],
        [
            'key' => 'limit',
            'label' => 'Dynamic Options Limit',
            'type' => 'number',
            'default' => 20,
            'min' => 1,
            'helpText' => 'Maximum number of record results returned per request.',
        ],
        [
            'key' => 'advancedQuery',
            'label' => 'Advanced Query',
            'type' => 'textarea',
            'default' => '',
            'helpText' => "Optional line-based filters. One clause per line: Field OP Value. Example:\nRecordTypeId = 012xxxxxxxxxxxx\nCreatedDate >= 2024-01-01\nEmail LIKE %@example.com",
        ],
    ];

    /**
     * @return array<int, array{value:string,text:string}>
     */
    public function resolve(\WP_REST_Request $request): array {
        if (!$this->isAvailable()) {
            return [];
        }

        $objectApiName = $this->normaliseObjectApiName((string) ($request->get_param('objectApiName') ?: ''));

        if ($objectApiName === '') {
            return [];
        }

        $search = trim(sanitize_text_field((string) ($request->get_param('search') ?: '')));
        $selected = $this->normaliseSelectedValues($request->get_param('selected'));
        $limit = max(1, min(200, (int) ($request->get_param('limit') ?: 20)));
        $queryLimit = $search !== '' ? max($limit, min(200, max($limit * 5, 50))) : $limit;
        $connection = sanitize_text_field((string) ($request->get_param('connection') ?: ''));
        $valueField = $this->normaliseFieldName((string) ($request->get_param('valueField') ?: 'Id'));
        $labelField = $this->normaliseFieldName((string) ($request->get_param('labelField') ?: 'Name'));
        $searchField = $this->normaliseFieldName((string) ($request->get_param('searchField') ?: $labelField));
        $fields = $this->parseFieldList((string) ($request->get_param('fields') ?: 'Id,Name'));
        $advancedQuery = trim(sanitize_textarea_field((string) ($request->get_param('advancedQuery') ?: '')));

        $valueField = $valueField !== '' ? $valueField : 'Id';
        $labelField = $labelField !== '' ? $labelField : $valueField;
        $searchField = $searchField !== '' ? $searchField : $labelField;

        $queryFields = array_values(array_unique(array_filter(array_merge($fields, [$valueField, $labelField, $searchField]))));

        $query = SalesforceDynamicObject::forObject($objectApiName, $connection)
            ->select($queryFields)
            ->limit($queryLimit);

        if ($search !== '') {
            $query->where($searchField, 'LIKE', '%' . $search . '%');
        }

        if ($advancedQuery !== '') {
            $this->applyAdvancedQuery($query, $advancedQuery);
        }

        if ($selected !== []) {
            $query->where($valueField, 'IN', $selected);
        }

        $records = $query->get();

        if ($search !== '') {
            $records = array_values(array_filter($records, function ($record) use ($search, $searchField) {
                if (!is_array($record)) {
                    return false;
                }

                $haystack = (string) ($record[$searchField] ?? '');

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
     * @return array<int, string>
     */
    private function normaliseSelectedValues(mixed $selected): array {
        if (is_string($selected)) {
            $selected = array_filter(array_map('trim', explode(',', $selected)));
        }

        if (!is_array($selected)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($value) => trim((string) $value), $selected)));
    }

    private function normaliseObjectApiName(string $objectApiName): string {
        return preg_replace('/[^A-Za-z0-9_]/', '', trim($objectApiName)) ?: '';
    }

    private function normaliseFieldName(string $field): string {
        $field = trim($field);

        if ($field === '') {
            return '';
        }

        return preg_replace('/[^A-Za-z0-9_\.]/', '', $field) ?: '';
    }

    /**
     * @return array<int, string>
     */
    private function parseFieldList(string $fields): array {
        $parts = explode(',', $fields);

        return array_values(array_filter(array_map(fn ($field) => $this->normaliseFieldName((string) $field), $parts)));
    }

    /**
     * @return array<int, string>
     */
    private function allowedOperators(): array {
        return ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IN', 'NOT IN'];
    }

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

            $field = $this->normaliseFieldName((string) ($matches[1] ?? ''));
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
}