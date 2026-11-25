<?php

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use MM\Meros\Contracts\Features\Admin\SettingsContainer;
use MM\Meros\Contracts\Features\Automation\FlowAction;
use MM\Meros\Contracts\Features\Automation\FlowActionResponse;
use MM\Meros\Contracts\Features\Components\FieldRow;
use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\Contracts\Providers\FeatureProvider;
use MM\Meros\Crm\App\Components\Orchestrator;
use MM\Meros\Crm\App\Integrations\Salesforce;
use MM\Meros\Crm\App\Jobs\InvokeSalesforceFlow;
use MM\Meros\Crm\Facades\Salesforce as SalesforceFacade;
use MM\Meros\Facades\Automation\FlowActions;
use MM\Meros\Facades\Support\Registers;
use MM\Meros\Registers\Admin\SettingsContainers;
use MM\Meros\Registers\Automation\FlowActions as FlowActionsRegister;
use MM\Meros\Support\FlowRunner;
use MM\Meros\Support\FlowControls;
use MM\Meros\Support\FormActionsRunner;
use MM\Meros\Support\HttpClient;

function get_theme_file_path(): string { return dirname(dirname(__DIR__), 4); }
function get_template_directory_uri(): string { return 'https://example.test/theme'; }
function wp_unslash(string $value): string { return stripslashes($value); }
function wp_kses_post(string $value): string { return $value; }
function add_filter(string $hook, Closure $callback, int $priority = 10): void {
    $GLOBALS['filters'][$hook][$priority][spl_object_id($callback)] = $callback;
}
function add_action(string $hook, Closure $callback, int $priority = 10): void {
    add_filter($hook, $callback, $priority);
}
function remove_filter(string $hook, Closure $callback, int $priority = 10): void {
    unset($GLOBALS['filters'][$hook][$priority][spl_object_id($callback)]);
}

function get_option(string $name, mixed $default = false): mixed {
    if (isset($GLOBALS['enabledOptions'][$name])) {
        return ['test_salesforce_enabled' => $GLOBALS['enabledOptions'][$name]];
    }

    return [
        'base_url_default' => 'https://example.my.salesforce.com',
        'sf_queryable_objects_default' => [
            ['sf_object_name' => 'Contact', 'sf_object_nickname' => 'subscriber', 'sf_object_fields' => 'Id, Email, LastName, HasOptedOutOfEmail, Count__c'],
            ['sf_object_name' => 'Invoice__c', 'sf_object_nickname' => 'invoice', 'sf_object_fields' => 'All'],
        ],
    ];
}

function check(bool $condition, string $message): void {
    \PHPUnit\Framework\Assert::assertTrue($condition, $message);
}

function expectFailure(callable $callback, string $message): void {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), $message), 'Unexpected failure: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

class FlowTestSettings extends SettingsContainer {
    public function initialiseForTest(): void {
        $this->identifier('name', 'snake');
        $this->name = 'salesforce_flow_test_settings';
    }
}

class FlowTestEnableSettings extends SettingsContainer {
    public function initialiseForTest(string $name): void {
        $this->identifier('name', 'snake');
        $this->name = $name;
    }
}

class FlowTestHttpClient extends HttpClient {
    public array $requests = [];
    public array $responses = [];

    public function send(array $request): Response {
        $this->requests[] = $request;
        [$status, $body] = array_shift($this->responses);
        return new Response(new GuzzleResponse($status, [], $body));
    }
}

class FlowTestSalesforce extends Salesforce {
    public FlowTestHttpClient $client;
    public string $enableSettingName;
    public ?string $token = 'old-token';
    public ?string $refreshedToken = 'new-token';
    public int $refreshes = 0;
    public array $errors = [];
    public ?array $flow = [
        'sf_flow_name' => 'MT_Website_Test',
        'sf_flow_inputs_cache' => [
            ['name' => 'testVar', 'type' => 'STRING', 'required' => false, 'picklistValues' => null],
        ],
        'sf_flow_outputs_cache' => [
            ['name' => 'Flow__InterviewStatus', 'type' => 'PICKLIST', 'picklistValues' => [
                ['value' => 'Finished'], ['value' => 'Waiting'],
            ]],
            ['name' => 'Flow__InterviewGuid', 'type' => 'STRING', 'picklistValues' => null],
        ],
    ];

    protected function getConnection(): ?array {
        return ['status' => 'connected'];
    }

    public function getInvokableFlow(string $flowName, bool $refresh = false): ?array {
        return $this->flow;
    }

    public function getInvokableFlows(bool $refresh = false): array {
        return $this->flow === null ? [] : [$this->flow];
    }

    protected function getAccessToken(): ?string {
        return $this->token;
    }

    protected function refreshToken(): ?string {
        $this->refreshes++;
        $this->token = $this->refreshedToken;
        return $this->token;
    }

    protected function httpClient(): HttpClient {
        return $this->client;
    }

    protected function logError(string $title, string $message = '', ?int $code = null): void {
        $this->errors[] = [$title, $message];
    }
}

function integration(array $responses): FlowTestSalesforce {
    $settings = (new ReflectionClass(FlowTestSettings::class))->newInstanceWithoutConstructor();
    $settings->initialiseForTest();
    $integration = (new ReflectionClass(FlowTestSalesforce::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Integration::class, 'settings'))->setValue($integration, $settings);
    $enableSettings = (new ReflectionClass(FlowTestEnableSettings::class))->newInstanceWithoutConstructor();
    $integration->enableSettingName = 'salesforce_enabled_' . spl_object_id($enableSettings);
    $enableSettings->initialiseForTest($integration->enableSettingName);
    $GLOBALS['enabledOptions'][$integration->enableSettingName] = true;
    (new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($integration, $enableSettings);
    (new ReflectionMethod(Integration::class, 'identifier'))->invoke($integration, 'name', 'snake');
    (new ReflectionProperty(Integration::class, 'name'))->setValue($integration, 'test_salesforce');
    (new ReflectionProperty(Integration::class, 'enabled'))->setValue($integration, true);
    $integration->client = new FlowTestHttpClient();
    $integration->client->responses = $responses;
    return $integration;
}
