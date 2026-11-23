<?php

namespace MM\Meros\Crm\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use MM\Meros\Crm\App\Integrations\Salesforce;

class InvokeSalesforceFlow implements ShouldQueue {
    public const CONNECTION = 'meros_crm';

    // A timeout can occur after Salesforce has committed the flow's side effects.
    public int $tries = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(
        public string $flowName,
        public array $inputs,
        public string $environment
    ) {}

    public function handle(Salesforce $salesforce): void {
        if ($salesforce->getCurrentEnvironment() !== $this->environment ||
            $salesforce->getCurrentEnvironment(true) !== $this->environment
        ) {
            throw new \RuntimeException('The Salesforce environment changed after this flow was queued.');
        }

        $salesforce->runQueuedFlow($this->flowName, $this->inputs);
    }
}
