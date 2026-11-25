<?php

namespace MM\Meros\Crm\Tests\Support {
    use MM\Meros\Contracts\Features\Admin\SettingsContainer;
    use MM\Meros\Crm\App\Integrations\Salesforce;

    class SalesforceReadinessSettings extends SettingsContainer {
        public function initialiseForTest(): void {
            $this->identifier('name', 'snake');
            $this->name = 'salesforce_readiness_options';
        }
    }

    class SalesforceReadinessDouble extends Salesforce {
        public ?string $token = 'token';
        public array $errors = [];
        protected function getConnection(): ?array { return ['status' => 'connected']; }
        protected function getAccessToken(): ?string { return $this->token; }
        protected function logError(string $title, string $message = '', ?int $code = null): void {
            $this->errors[] = [$title, $message];
        }
    }
}

namespace {
    function get_option(string $name, mixed $default = false): mixed {
        return $GLOBALS['options'][$name] ?? $default;
    }
}
