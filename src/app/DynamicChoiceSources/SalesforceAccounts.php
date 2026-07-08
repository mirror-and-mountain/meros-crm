<?php

namespace MM\Meros\Crm\App\DynamicChoiceSources;

use MM\Meros\Crm\App\Integrations\ExternalModels\SalesforceAccount;
use MM\Meros\Crm\App\Integrations\ExternalModels\SalesforceObject;
use MM\Meros\Services\Contracts\Forms\DynamicChoiceSource;

class SalesforceAccounts extends DynamicChoiceSource {
    public string $source = 'salesforce_accounts';

    protected string $label = 'Salesforce Accounts';

    protected string $description = 'Query Salesforce Account records.';

    protected array $configFields = [
        [
            'key' => 'connection',
            'label' => 'Salesforce Connection',
            'type' => 'text',
            'default' => '',
            'helpText' => 'Optional integration connection label. Leave empty for the default active connection.',
        ],
        [
            'key' => 'limit',
            'label' => 'Dynamic Options Limit',
            'type' => 'number',
            'default' => 20,
            'min' => 1,
            'helpText' => 'Maximum number of account results returned per request.',
        ],
        [
            'key' => 'valueField',
            'label' => 'Value Field',
            'type' => 'text',
            'default' => 'Id',
            'helpText' => 'Field returned as option value. Defaults to Salesforce record Id.',
        ],
        [
            'key' => 'searchField',
            'label' => 'Search Field',
            'type' => 'select',
            'default' => 'Name',
            'options' => [
                'Name' => 'Name',
                'Type' => 'Type',
                'Industry' => 'Industry',
                'Website' => 'Website',
                'BillingCity' => 'Billing City',
            ],
            'helpText' => 'Salesforce field used when searching for options.',
        ],
        [
            'key' => 'type',
            'label' => 'Account Type',
            'type' => 'text',
            'default' => '',
            'helpText' => 'Only include accounts of this Salesforce Type value.',
        ],
        [
            'key' => 'parentId',
            'label' => 'Parent Account Id',
            'type' => 'text',
            'default' => '',
            'helpText' => 'Only include accounts with this ParentId.',
        ],
        [
            'key' => 'recordTypeId',
            'label' => 'Record Type Id',
            'type' => 'text',
            'default' => '',
            'helpText' => 'Only include accounts for this Record Type Id.',
        ],
        [
            'key' => 'advancedQuery',
            'label' => 'Advanced Query',
            'type' => 'textarea',
            'default' => '',
            'helpText' => "Optional line-based filters. One clause per line: Field OP Value. Example:\nType = Customer\nBillingCountry = United Kingdom\nAnnualRevenue >= 1000000",
        ],
    ];

    /**
     * @return array<int, array{value:string,text:string}>
     */
    public function resolve(\WP_REST_Request $request): array {
        if (!$this->isAvailable()) {
            return [];
        }

        $search = trim(sanitize_text_field((string) ($request->get_param('search') ?: '')));
        $selected = $this->normaliseSelectedValues($request->get_param('selected'));
        $limit = max(1, min(200, (int) ($request->get_param('limit') ?: 20)));
        $connection = sanitize_text_field((string) ($request->get_param('connection') ?: ''));
        $valueField = $this->normaliseFieldName((string) ($request->get_param('valueField') ?: 'Id'));
        $searchField = $this->normaliseFieldName((string) ($request->get_param('searchField') ?: 'Name'));
        $type = trim(sanitize_text_field((string) ($request->get_param('type') ?: '')));
        $parentId = trim(sanitize_text_field((string) ($request->get_param('parentId') ?: '')));
        $recordTypeId = trim(sanitize_text_field((string) ($request->get_param('recordTypeId') ?: '')));
        $advancedQuery = trim(sanitize_textarea_field((string) ($request->get_param('advancedQuery') ?: '')));

        $valueField = $valueField !== '' ? $valueField : 'Id';

        $selectFields = array_values(array_unique(['Id', 'Name', 'Type', 'Industry', 'Website', $valueField]));

        $query = SalesforceAccount::init($connection)
            ->select($selectFields)
            ->limit($limit);

        if ($type !== '') {
            $query->where('Type', $type);
        }

        if ($parentId !== '') {
            $query->where('ParentId', $parentId);
        }

        if ($recordTypeId !== '') {
            $query->where('RecordTypeId', $recordTypeId);
        }

        if ($search !== '') {
            $query->where($searchField !== '' ? $searchField : 'Name', 'LIKE', '%' . $search . '%');
        }

        if ($advancedQuery !== '') {
            $this->applyAdvancedQuery($query, $advancedQuery);
        }

        if ($selected !== []) {
            $query->where($valueField, 'IN', $selected);
        }

        $records = $query->get();

        return array_values(array_filter(array_map(function ($record) use ($valueField) {
            if (!is_array($record)) {
                return null;
            }

            $value = trim((string) ($record[$valueField] ?? ''));

            if ($value === '') {
                return null;
            }

            $name = trim((string) ($record['Name'] ?? ''));

            return [
                'value' => $value,
                'text' => $name !== '' ? $name : $value,
            ];
        }, $records)));
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
