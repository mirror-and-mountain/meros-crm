<?php

require __DIR__ . '/../../../autoload.php';
require __DIR__ . '/../src/app/Integrations/Salesforce.php';

use MM\Meros\Contracts\Features\Admin\SettingsContainer;
use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\Crm\App\Integrations\Salesforce;

function get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['options'][$name] ?? $default;
}

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class SalesforceReadinessSettings extends SettingsContainer {
    public function initialiseForTest(): void {
        $this->identifier('name', 'snake');
        $this->name = 'salesforce_readiness_options';
    }
}

class SalesforceReadinessTest extends Salesforce {
    public ?string $token = 'token';
    public array $errors = [];

    protected function getConnection(): ?array {
        return ['status' => 'connected'];
    }

    protected function getAccessToken(): ?string {
        return $this->token;
    }

    protected function logError(string $title, string $message = '', ?int $code = null): void {
        $this->errors[] = [$title, $message];
    }
}

$settings = (new ReflectionClass(SalesforceReadinessSettings::class))->newInstanceWithoutConstructor();
$settings->initialiseForTest();
$salesforce = (new ReflectionClass(SalesforceReadinessTest::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(Integration::class, 'identifier'))->invoke($salesforce, 'name', 'snake');
(new ReflectionProperty(Integration::class, 'name'))->setValue($salesforce, 'salesforce_test');
(new ReflectionProperty(Integration::class, 'settings'))->setValue($salesforce, $settings);
(new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($salesforce, $settings);
$GLOBALS['options']['salesforce_readiness_options'] = ['salesforce_test_enabled' => true];

check($salesforce->ready(), 'A connected and enabled Salesforce integration must be ready.');
$GLOBALS['options']['salesforce_readiness_options']['salesforce_test_enabled'] = false;
check(!$salesforce->ready(), 'A changed enabled setting must be reflected in readiness.');
check($salesforce->get(false, false) === null, 'The old disabled-state override must not permit requests.');
$request = new ReflectionMethod(Salesforce::class, 'request');
check(str_contains($request->invoke($salesforce, 'https://example.test', []), 'disabled'), 'Direct Salesforce requests must enforce readiness.');
check(count($salesforce->errors) === 2, 'Readiness failures must be logged.');

$GLOBALS['options']['salesforce_readiness_options']['salesforce_test_enabled'] = true;
$salesforce->token = null;
check(!$salesforce->ready(), 'A connected Salesforce integration without a token must not be ready.');
check($salesforce->invokeFlow('Test', []) === 'Cannot locate access token for request.', 'Missing-token errors must retain the existing failure contract.');

echo "Salesforce readiness tests passed.\n";
