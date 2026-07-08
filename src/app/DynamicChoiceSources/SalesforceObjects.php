<?php

namespace MM\Meros\Crm\App\DynamicChoiceSources;

use MM\Meros\Crm\App\Integrations\ExternalModels\SalesforceSObject;
use MM\Meros\Services\Contracts\Forms\DynamicChoiceSource;

class SalesforceObjects extends DynamicChoiceSource {
    public string $source = 'salesforce_objects';

    protected string $label = 'Salesforce Objects';

    protected string $description = 'Query available Salesforce sObjects.';

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
            'helpText' => 'Maximum number of object results returned per request.',
        ],
    ];

    /**
     * @return array<int, array{value:string,text:string}>
     */
    public function resolve(\WP_REST_Request $request): array {
        if (!$this->isAvailable()) {
            return [];
        }

        $search = strtolower(trim(sanitize_text_field((string) ($request->get_param('search') ?: ''))));
        $selected = $this->normaliseSelectedValues($request->get_param('selected'));
        $limit = max(1, min(100, (int) ($request->get_param('limit') ?: 20)));
        $connection = sanitize_text_field((string) ($request->get_param('connection') ?: ''));

        $objects = $this->resolveObjects($connection);

        $options = array_values(array_filter(array_map(function ($object) {
            if (!is_array($object)) {
                return null;
            }

            $name = trim((string) ($object['name'] ?? ''));

            if ($name === '') {
                return null;
            }

            $label = trim((string) ($object['label'] ?? ''));

            return [
                'value' => $name,
                'text' => $label !== '' ? $label : $name,
            ];
        }, $objects)));

        if ($selected !== [] && $search === '') {
            $selectedSet = array_flip($selected);

            return array_values(array_filter($options, fn ($option) => isset($selectedSet[$option['value']])));
        }

        if ($search !== '') {
            $options = array_values(array_filter($options, function ($option) use ($search) {
                $value = strtolower((string) ($option['value'] ?? ''));
                $text = strtolower((string) ($option['text'] ?? ''));

                return str_contains($value, $search) || str_contains($text, $search);
            }));
        }

        return array_slice($options, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveObjects(string $connection): array {
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
