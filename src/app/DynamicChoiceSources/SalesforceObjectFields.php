<?php

namespace MM\Meros\Crm\App\DynamicChoiceSources;

use MM\Meros\Crm\App\Integrations\ExternalModels\SalesforceSObject;
use MM\Meros\Services\Contracts\Forms\DynamicChoiceSource;

class SalesforceObjectFields extends DynamicChoiceSource {
    public string $source = 'salesforce_object_fields';

    protected string $label = 'Salesforce Object Fields';

    protected string $description = 'Query field definitions for a specific Salesforce object.';

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
            'default' => 'contact',
            'helpText' => 'Salesforce object API name, for example Contact, Account, or Lead.',
        ],
        [
            'key' => 'limit',
            'label' => 'Dynamic Options Limit',
            'type' => 'number',
            'default' => 50,
            'min' => 1,
            'helpText' => 'Maximum number of field results returned per request.',
        ],
    ];

    /**
     * @return array<int, array{value:string,text:string}>|array{options:array<int, array{value:string,text:string}>,debug:array<string,mixed>}
     */
    public function resolve(\WP_REST_Request $request): array {
        if (!$this->isAvailable()) {
            return [];
        }

        $debugEnabled = (bool) $request->get_param('debug');

        $requestedObjectApiName = trim(sanitize_text_field((string) ($request->get_param('objectApiName') ?: '')));

        if ($requestedObjectApiName === '') {
            return [];
        }

        $search = strtolower(trim(sanitize_text_field((string) ($request->get_param('search') ?: ''))));
        $selected = $this->normaliseSelectedValues($request->get_param('selected'));
        $limit = max(1, min(200, (int) ($request->get_param('limit') ?: 50)));
        $connection = sanitize_text_field((string) ($request->get_param('connection') ?: ''));

        $objectApiName = $this->canonicaliseObjectApiName($requestedObjectApiName, $connection);

        $fields = $this->resolveObjectFields($objectApiName, $connection);

        $options = array_values(array_filter(array_map(function ($field) {
            if (!is_array($field)) {
                return null;
            }

            $name = trim((string) ($field['name'] ?? ''));

            if ($name === '') {
                return null;
            }

            $label = trim((string) ($field['label'] ?? ''));

            return [
                'value' => $name,
                'text' => $label !== '' ? $label : $name,
            ];
        }, $fields)));

        if ($selected !== [] && $search === '') {
            $selectedSet = array_flip($selected);

            $options = array_values(array_filter($options, fn ($option) => isset($selectedSet[$option['value']])));
        }

        if ($search !== '') {
            $options = array_values(array_filter($options, function ($option) use ($search) {
                $value = strtolower((string) ($option['value'] ?? ''));
                $text = strtolower((string) ($option['text'] ?? ''));

                return str_contains($value, $search) || str_contains($text, $search);
            }));
        }

        $finalOptions = array_slice($options, 0, $limit);

        if (!$debugEnabled) {
            return $finalOptions;
        }

        return [
            'options' => $finalOptions,
            'debug' => [
                'source' => $this->source,
                'requestedObjectApiName' => $requestedObjectApiName,
                'resolvedObjectApiName' => $objectApiName,
                'connection' => $connection,
                'search' => $search,
                'selectedCount' => count($selected),
                'fieldsCount' => count($fields),
                'optionsCount' => count($finalOptions),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveObjectFields(string $objectApiName, string $connection): array {
        $candidates = $this->buildObjectApiNameCandidates($objectApiName);

        foreach ($candidates as $candidate) {
            try {
                $fields = SalesforceSObject::init($connection)->fields($candidate);

                if (is_array($fields) && $fields !== []) {
                    return $fields;
                }
            } catch (\Throwable $exception) {
                // Continue trying candidate variants and fallback connection.
            }
        }

        if ($connection !== '') {
            foreach ($candidates as $candidate) {
                try {
                    $fallback = SalesforceSObject::init()->fields($candidate);

                    if (is_array($fallback) && $fallback !== []) {
                        return $fallback;
                    }
                } catch (\Throwable $exception) {
                    // Continue to next candidate.
                }
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function buildObjectApiNameCandidates(string $objectApiName): array {
        $base = trim($objectApiName);

        if ($base === '') {
            return [];
        }

        $variants = [
            $base,
            strtolower($base),
            ucfirst(strtolower($base)),
        ];

        // Preserve custom object suffix casing while still trying a normalized prefix.
        if (str_contains($base, '__')) {
            [$prefix, $suffix] = explode('__', $base, 2);
            $variants[] = strtolower($prefix) . '__' . $suffix;
            $variants[] = ucfirst(strtolower($prefix)) . '__' . $suffix;
        }

        return array_values(array_unique(array_filter(array_map('trim', $variants), fn ($item) => $item !== '')));
    }

    private function canonicaliseObjectApiName(string $objectApiName, string $connection): string {
        $candidate = trim($objectApiName);

        if ($candidate === '') {
            return '';
        }

        $objects = $this->resolveObjectsList($connection);

        if ($objects === []) {
            return $candidate;
        }

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $name = trim((string) ($object['name'] ?? ''));
            $label = trim((string) ($object['label'] ?? ''));

            if ($name !== '' && strcasecmp($name, $candidate) === 0) {
                return $name;
            }

            if ($label !== '' && strcasecmp($label, $candidate) === 0) {
                return $name !== '' ? $name : $candidate;
            }
        }

        // If a record id is accidentally supplied, map its key prefix to the object API name.
        if (preg_match('/^[A-Za-z0-9]{15}(?:[A-Za-z0-9]{3})?$/', $candidate) === 1) {
            $prefix = strtoupper(substr($candidate, 0, 3));

            foreach ($objects as $object) {
                if (!is_array($object)) {
                    continue;
                }

                $name = trim((string) ($object['name'] ?? ''));
                $keyPrefix = strtoupper(trim((string) ($object['keyPrefix'] ?? '')));

                if ($name !== '' && $keyPrefix !== '' && $keyPrefix === $prefix) {
                    return $name;
                }
            }
        }

        return $candidate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveObjectsList(string $connection): array {
        try {
            $objects = SalesforceSObject::init($connection)->all();

            if (is_array($objects) && $objects !== []) {
                return $objects;
            }
        } catch (\Throwable $exception) {
            // Fall through to default active connection fallback below.
        }

        if ($connection !== '') {
            try {
                $fallback = SalesforceSObject::init()->all();

                return is_array($fallback) ? $fallback : [];
            } catch (\Throwable $exception) {
                return [];
            }
        }

        return [];
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
}
