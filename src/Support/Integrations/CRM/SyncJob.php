<?php

namespace MM\Meros\Crm\Support\Integrations\CRM;

/**
 * Class SyncJob
 *
 * Represents a synchronization job for an integration.
 * This class encapsulates the details of a sync job, including the integration handle,
 * the object to be synchronized, the HTTP method, endpoint, connection label, environment,
 * mappings, and metadata.
 *
 * @package MM\Meros\Crm\Support\Integrations\CRM
 */
final class SyncJob {
    public function __construct(
        public readonly string $integrationHandle,
        public readonly string $object,
        public readonly string $method = 'POST',
        public readonly string $endpoint = '',
        public readonly string $connectionLabel = '',
        public readonly string $environment = '',
        public readonly array $mappings = [],
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Creates a SyncJob instance from an associative array.
     *
     * @param array $payload The associative array containing the sync job details.
     *
     * @return static Returns a new instance of SyncJob.
     */
    public static function fromArray(array $payload): self {
        return new self(
            integrationHandle: trim((string) ($payload['integration_handle'] ?? '')),
            object: trim((string) ($payload['object'] ?? $payload['object_name'] ?? '')),
            method: strtoupper(trim((string) ($payload['method'] ?? 'POST'))),
            endpoint: trim((string) ($payload['endpoint'] ?? '')),
            connectionLabel: trim((string) ($payload['connection_label'] ?? '')),
            environment: trim((string) ($payload['environment'] ?? '')),
            mappings: is_array($payload['mappings'] ?? null) ? $payload['mappings'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    /**
     * Validates the SyncJob instance to ensure it has the required properties set.
     *
     * @return bool Returns true if the SyncJob is valid; otherwise, false.
     */
    public function isValid(): bool {
        return $this->integrationHandle !== '' && ($this->object !== '' || $this->endpoint !== '');
    }

    /**
     * Resolves the endpoint for the sync job.
     *
     * If an explicit endpoint is provided, it returns that; otherwise, it returns the object name.
     *
     * @return string The resolved endpoint.
     */
    public function resolveEndpoint(): string {
        if ($this->endpoint !== '') {
            return ltrim($this->endpoint, '/');
        }

        return ltrim($this->object, '/');
    }
}
