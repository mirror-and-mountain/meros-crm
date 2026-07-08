<?php

namespace MM\Meros\Crm\App\Integrations\ExternalModels;

use MM\Meros\Support\ExternalModel;
use MM\Meros\Support\Concerns\UsesCaching;

/**
 * Salesforce org metadata model for sObject discovery and inspection.
 */
class SalesforceSObject extends ExternalModel {
    use UsesCaching;

    protected int $objectsCacheTtlSeconds = 900;

    protected int $describeCacheTtlSeconds = 3600;

    public function __construct() {
        parent::__construct();

        $this->integration('salesforce')
            ->usingEnvironment('production')
            ->path('sobjects');
    }

    /**
     * Selects a specific integration connection label.
     */
    public function usingConnection(string $label): static {
        $this->using($label);

        return $this;
    }

    /**
     * Returns all available Salesforce objects from the org.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array {
        return $this->rememberCache('sobjects.all', function (): array {
            $this->resetHttpState();

            $response = parent::get();

            $this->throwIfFailed($response, 'list Salesforce objects');

            $payload = $response->json() ?? [];

            if (!is_array($payload['sobjects'] ?? null)) {
                return [];
            }

            return $payload['sobjects'];
        }, [], $this->objectsCacheTtlSeconds);
    }

    /**
     * Gets Salesforce object metadata.
     *
     * When no object name is provided, returns the list of org objects.
     */
    public function get(string $objectApiName = ''): array {
        if (trim($objectApiName) === '') {
            return $this->all();
        }

        return $this->describe($objectApiName);
    }

    /**
     * Returns describe metadata for a specific Salesforce object.
     */
    public function describe(string $objectApiName): array {
        $objectApiName = trim($objectApiName);

        if ($objectApiName === '') {
            return [];
        }

        return $this->rememberCache('sobjects.describe', function () use ($objectApiName): array {
            $this->resetHttpState();

            $response = parent::get(rawurlencode($objectApiName) . '/describe');

            $this->throwIfFailed($response, 'describe Salesforce object [' . $objectApiName . ']');

            return $response->json() ?? [];
        }, ['object' => strtolower($objectApiName)], $this->describeCacheTtlSeconds);
    }

    /**
     * Returns field definitions for a specific Salesforce object.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(string $objectApiName): array {
        $description = $this->describe($objectApiName);

        if (!is_array($description['fields'] ?? null)) {
            return [];
        }

        return $description['fields'];
    }

    /**
     * Finds an object by API name from the org object listing.
     */
    public function find(string $objectApiName): ?array {
        $objectApiName = trim($objectApiName);

        if ($objectApiName === '') {
            return null;
        }

        foreach ($this->all() as $object) {
            if (!is_array($object)) {
                continue;
            }

            $name = (string) ($object['name'] ?? '');

            if (strcasecmp($name, $objectApiName) === 0) {
                return $object;
            }
        }

        return null;
    }

    /**
     * Clears cached list + describe payload for a specific object.
     */
    public function forgetObjectCache(string $objectApiName): void {
        $objectApiName = trim($objectApiName);

        if ($objectApiName !== '') {
            $this->forgetCache('sobjects.describe', ['object' => strtolower($objectApiName)]);
        }

        $this->forgetCache('sobjects.all');
    }

    protected function cachePrefix(): string {
        return 'meros:salesforce:sobjects';
    }

    protected function resetHttpState(): void {
        $this->query = [];
        $this->headers = [];
        $this->payload = [];
        $this->format = 'json';
    }
}
