<?php

namespace MM\Meros\Crm\Support\Integrations\CRM;

use MM\Meros\App\Integrations\ExternalModels\GenericResource;
use MM\Meros\Services\Contracts\Integration;
use MM\Meros\Facades\Integrations;

/**
 * Class SyncJobRunner
 *
 * This class is responsible for executing synchronization jobs for CRM integrations.
 * It takes a SyncJob instance and processes it, sending the appropriate HTTP requests
 * to the specified integration's API endpoint.
 *
 * @package MM\Meros\Crm\Support\Integrations\CRM
 */
final class SyncJobRunner {
    public function __construct(
        protected SyncValueResolver $valueResolver
    ) {
    }

    /**
     * Runs multiple synchronization jobs and returns the results.
     *
     * @param array $jobs An array of SyncJob instances or associative arrays representing sync jobs.
     * @param array $submission The submission data to be used for resolving mappings.
     * @param array $context Optional context data for the sync jobs.
     *
     * @return array An array of results for each sync job, indicating success or failure.
     */
    public function runMany(array $jobs, array $submission, array $context = []): array {
        $results = [];

        foreach ($jobs as $index => $jobData) {
            try {
                $job = $jobData instanceof SyncJob ? $jobData : SyncJob::fromArray((array) $jobData);

                if (!$job->isValid()) {
                    $results[] = [
                        'ok'    => false,
                        'index' => $index,
                        'error' => 'Invalid sync job configuration.',
                    ];
                    continue;
                }

                $response = $this->run($job, $submission, $context);

                $results[] = [
                    'ok'       => true,
                    'index'    => $index,
                    'response' => $response,
                ];
            } catch (\Throwable $exception) {
                report($exception);

                $results[] = [
                    'ok'    => false,
                    'index' => $index,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Runs a single synchronization job and returns the result.
     *
     * @param SyncJob $job The SyncJob instance to be executed.
     * @param array   $submission The submission data to be used for resolving mappings.
     * @param array   $context Optional context data for the sync job.
     *
     * @return array The response from the integration's API.
     *
     * @throws \RuntimeException If the integration is not found or if the sync request fails.
     */
    public function run(SyncJob $job, array $submission, array $context = []): array {
        $integration = Integrations::get($job->integrationHandle);

        if (!$integration instanceof Integration) {
            throw new \RuntimeException('Integration not found for handle: ' . $job->integrationHandle);
        }

        if ($integration->getCategory() !== 'crm') {
            throw new \RuntimeException('Integration is not a CRM integration: ' . $job->integrationHandle);
        }

        $payload = $this->buildPayload($job, $submission);

        /** @var GenericResource $resource */
        $resource = app(GenericResource::class);
        $resource->integration($job->integrationHandle);

        if ($job->connectionLabel !== '') {
            $resource->using($job->connectionLabel);
        }

        if ($job->environment !== '') {
            $resource->usingEnvironment($job->environment);
        }

        $method = strtoupper($job->method);
        $endpoint = $job->resolveEndpoint();

        $response = match ($method) {
            'POST'   => $resource->asJson()->payload($payload)->post($endpoint),
            'PUT'    => $resource->asJson()->payload($payload)->put($endpoint),
            'PATCH'  => $resource->asJson()->payload($payload)->patch($endpoint),
            'DELETE' => $resource->asJson()->payload($payload)->delete($endpoint),
            default  => $resource->asJson()->payload($payload)->request($method, $endpoint),
        };

        if ($response->failed()) {
            throw new \RuntimeException('CRM sync request failed: ' . $response->body());
        }

        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * Builds the payload for the sync job based on the mappings and submission data.
     *
     * @param SyncJob $job The SyncJob instance containing the mappings.
     * @param array   $submission The submission data to be used for resolving mappings.
     *
     * @return array The constructed payload for the sync job.
     */
    private function buildPayload(SyncJob $job, array $submission): array {
        $payload = [];

        foreach ($job->mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $target = trim((string) ($mapping['target'] ?? $mapping['target_field'] ?? ''));

            if ($target === '') {
                continue;
            }

            $value = $this->valueResolver->resolve($mapping, $submission);

            if ($value === null || $value === '') {
                continue;
            }

            $payload[$target] = $value;
        }

        return $payload;
    }
}