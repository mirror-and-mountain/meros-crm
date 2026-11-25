<?php

namespace MM\Meros\Crm\Tests\Unit;

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
use MM\Meros\Support\Registers as RegistersRegistry;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

use PHPUnit\Framework\TestCase;

/**
 * Tests Salesforce record operations, flow invocation, queued persistence and form action integration.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesforceFlowTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__) . '/Support/SalesforceFlowTestFixtures.php';
    }

    public function testSalesforceRecordWritesAndQueries(): void {
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'queue' => [
                'default' => 'sync',
                'connections' => ['sync' => ['driver' => 'sync']],
                'failed' => ['driver' => 'file'],
            ],
        ]));
        $serviceProvider = new \MM\Meros\Crm\ServiceProvider($container);
        (new ReflectionMethod($serviceProvider, 'beforeBoot'))->invoke($serviceProvider);
        check(config('queue.default') === 'sync' && config('queue.failed') === ['driver' => 'file'], 'CRM queue configuration must not change the app default or failed-job provider.');
        Queue::swap(new \Illuminate\Queue\QueueManager($container));
        Queue::fake();
        
        $recordId = '003000000000001AAA';
        $created = ['id' => $recordId, 'success' => true, 'errors' => []];
        $createdBody = json_encode($created, JSON_THROW_ON_ERROR);
        $recordData = ['LastName' => 'Example', 'Email' => null, 'HasOptedOutOfEmail' => false, 'Count__c' => 0];
        $recordUrl = 'https://example.my.salesforce.com/services/data/v67.0/sobjects/Contact';
        foreach (['contacts', 'subscribers'] as $objectMethod) {
            $integration = integration([[201, $createdBody], [204, '']]);
            SalesforceFacade::swap($integration);
            $createdRecord = SalesforceFacade::{$objectMethod}()->create($recordData);
            check(is_object($createdRecord) && (array) $createdRecord === $created, 'Create must return Salesforce\'s result object using the object name or nickname.');
            check(SalesforceFacade::{$objectMethod}($recordId)->update($recordData) === true, 'Update must accept Salesforce\'s empty HTTP 204 response.');
            check(count($integration->client->requests) === 2, 'Record writes must not fetch the record afterward.');
            foreach (['POST', 'PATCH'] as $index => $method) {
                $request = $integration->client->requests[$index];
                check($request['method'] === $method && $request['format'] === 'json', 'Record writes must use JSON POST/PATCH.');
                check($request['url'] === $recordUrl . ($method === 'PATCH' ? "/{$recordId}" : ''), 'Aliases must resolve to the canonical Salesforce object API name.');
                check($request['payload'] === $recordData, 'Configured record fields must retain case, null, false, and zero.');
                check($request['headers']['Authorization'] === 'Bearer old-token', 'Writes must use the connected account.');
            }
            check($integration->errors === [], 'Successful writes must not log errors.');
        }
        
        $integration = integration([[201, $createdBody]]);
        check($integration->invoices()->create(['Amount__c' => 0]) !== null, 'Custom object aliases must support record creation.');
        check(str_ends_with($integration->client->requests[0]['url'], '/Invoice__c'), 'Custom object API names must retain their exact case and suffix.');
        
        foreach (['create', 'update'] as $operation) {
            $success = $operation === 'create' ? [201, $createdBody] : [204, ''];
            $integration = integration([$success]);
            $value = $integration->subscribers(...($operation === 'create' ? [] : [$recordId]))->{$operation}([
                'Email' => null, 'Unlisted__c' => 'Not allowed',
            ]);
            check($value === ($operation === 'create' ? null : false), 'A write containing an unlisted field must fail rather than partially applying allowed fields.');
            check($integration->client->requests === [] && $integration->errors !== [], 'Unlisted fields must be rejected and logged before any HTTP request.');
            check(str_contains($integration->errors[0][0], 'Unlisted__c') && str_contains($integration->errors[0][0], 'Contact'), 'Field validation errors must identify the rejected field and canonical object.');
        
            $integration = integration([$success]);
            $allFieldsData = ['New_Field__c' => null, 'Enabled__c' => false, 'Count__c' => 0];
            $value = $integration->invoices(...($operation === 'create' ? [] : [$recordId]))->{$operation}($allFieldsData);
            check($operation === 'create' ? is_object($value) : $value === true, 'An All field selection must permit any field in both write operations.');
            check($integration->client->requests[0]['payload'] === $allFieldsData && $integration->errors === [], 'All must preserve the complete field payload without logging field restrictions.');
        
            $integration = integration([[401, '[{"errorCode":"INVALID_SESSION_ID"}]'], $success]);
            $integration->contacts(...($operation === 'create' ? [] : [$recordId]))->{$operation}($recordData);
            check($integration->refreshes === 1 && count($integration->client->requests) === 2, 'Writes must refresh an expired token and retry once.');
            [$original, $retry] = $integration->client->requests;
            check($retry['method'] === $original['method'] && $retry['url'] === $original['url'] && $retry['payload'] === $recordData, 'Authentication retries must preserve the write verb, URL and field values.');
            check($retry['headers']['Authorization'] === 'Bearer new-token' && $integration->errors === [], 'A successful write retry must use the new token without logging an error.');
        
            foreach ([
                [400, '[{"errorCode":"REQUIRED_FIELD_MISSING","message":"Required field missing"}]'],
                [503, '{}'],
                [200, '<html>Not JSON</html>'],
                [200, '{"success":false}'],
            ] as $failure) {
                $integration = integration([$failure]);
                $value = $integration->contacts(...($operation === 'create' ? [] : [$recordId]))->{$operation}($recordData);
                check($value === ($operation === 'create' ? null : false), 'Failed or malformed write responses must not report success.');
                check($integration->errors !== [] && count($integration->client->requests) === 1 && $integration->refreshes === 0, 'Non-authentication write failures must be logged without replaying the write.');
            }
        
            $integration = integration([$success]);
            $integration->contacts(...($operation === 'create' ? [] : [$recordId]))->{$operation}([]);
            check(json_encode($integration->client->requests[0]['payload']) === '{}', 'An empty record field map must serialize as a JSON object, not a bulk array.');
        
            $integration = integration([]);
            $integration->contacts(...($operation === 'create' ? [] : [$recordId]))->not_configured($recordId)->{$operation}($recordData);
            check($integration->client->requests === [] && $integration->errors !== [], 'An invalid dynamic lookup must clear the previous target instead of writing to it.');
        }
        
        foreach ([
            fn ($sf) => $sf->create($recordData),
            fn ($sf) => $sf->records('Lead')->create($recordData),
            fn ($sf) => $sf->objects()->create($recordData),
            fn ($sf) => $sf->contacts($recordId)->create($recordData),
            fn ($sf) => $sf->contacts()->update($recordData),
            fn ($sf) => $sf->contacts('')->update($recordData),
            fn ($sf) => $sf->contacts()->where('Email', 'example@example.test')->update($recordData),
            fn ($sf) => $sf->record('Lead', $recordId)->update($recordData),
            fn ($sf) => $sf->object('Contact')->update($recordData),
            fn ($sf) => $sf->contacts()->create([['LastName' => 'Example']]),
            fn ($sf) => $sf->contacts($recordId)->update(['' => 'Invalid field']),
        ] as $invalidWrite) {
            $integration = integration([]);
            check(!$invalidWrite($integration), 'Invalid record write targets or payloads must fail.');
            check($integration->client->requests === [] && $integration->errors !== [], 'Invalid writes must be logged before sending HTTP requests.');
        }
        
        $integration = integration([]);
        $GLOBALS['enabledOptions'][$integration->enableSettingName] = false;
        check($integration->contacts()->create($recordData) === null && !$integration->contacts($recordId)->update($recordData), 'Disabled integrations must not write records.');
        check($integration->client->requests === [], 'Disabled writes must not contact Salesforce.');
        
        $integration = integration([[204, '']]);
        check($integration->contacts('id/with?query')->update($recordData), 'Selected record IDs must be sent as an encoded path segment.');
        check(str_ends_with($integration->client->requests[0]['url'], '/id%2Fwith%3Fquery'), 'Record IDs must not alter the endpoint path or query string.');
        
        $integration = integration([[200, '{"records":[{"Id":"003000000000001AAA"}]}']]);
        check($integration->subscribers()->get(false) === [['Id' => $recordId]], 'Existing GET queries must continue to resolve configured aliases.');
        check($integration->client->requests[0]['method'] === 'GET', 'Record-write support must preserve GET request behavior.');
        $integration->unknown_object()->get();
        check(count($integration->client->requests) === 1, 'A rejected dynamic lookup must not reuse a previous GET target.');
    }

    public function testFlowInvocationValidationRetriesAndOutput(): void {
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'queue' => [
                'default' => 'sync',
                'connections' => ['sync' => ['driver' => 'sync']],
                'failed' => ['driver' => 'file'],
            ],
        ]));
        $serviceProvider = new \MM\Meros\Crm\ServiceProvider($container);
        (new ReflectionMethod($serviceProvider, 'beforeBoot'))->invoke($serviceProvider);
        check(config('queue.default') === 'sync' && config('queue.failed') === ['driver' => 'file'], 'CRM queue configuration must not change the app default or failed-job provider.');
        Queue::swap(new \Illuminate\Queue\QueueManager($container));
        Queue::fake();
        
        $result = [
            'actionName' => 'MT_Website_Test',
            'isSuccess' => true,
            'errors' => null,
            'outputValues' => ['Flow__InterviewStatus' => 'Finished', 'Flow__InterviewGuid' => 'interview-guid'],
        ];
        $successBody = json_encode([$result], JSON_THROW_ON_ERROR);
        $integration = integration([[200, $successBody]]);
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === $result, 'Expected the complete single action result.');
        $request = $integration->client->requests[0];
        check($request['method'] === 'POST' && $request['format'] === 'json', 'Expected a JSON POST.');
        check($request['url'] === 'https://example.my.salesforce.com/services/data/v67.0/actions/custom/flow/MT_Website_Test', 'Expected the versioned flow endpoint.');
        check($request['headers']['Authorization'] === 'Bearer old-token', 'Expected bearer authentication.');
        check(json_encode($request['payload']) === '{"inputs":[{"testVar":"Hello"}]}', 'Expected a single invocation object and nullable picklist support.');
        check($integration->errors === [], 'Valid nullable picklist metadata must not log errors.');
        
        $integration = integration([[200, $successBody]]);
        $integration->flow['sf_flow_inputs_cache'] = [];
        $integration->invokeFlow('Namespaced/Flow', []);
        check(str_ends_with($integration->client->requests[0]['url'], '/Namespaced%2FFlow'), 'Expected an encoded flow API name.');
        check(json_encode($integration->client->requests[0]['payload']) === '{"inputs":[{}]}', 'A flow without inputs still needs one input object.');
        
        $integration = integration([[401, '[{"errorCode":"INVALID_SESSION_ID"}]'], [200, $successBody]]);
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === $result, 'Expected success after refreshing.');
        check($integration->refreshes === 1 && count($integration->client->requests) === 2, 'Expected exactly one refresh and retry.');
        $retry = $integration->client->requests[1];
        check($retry['method'] === 'POST' && $retry['payload'] == $request['payload'] && $retry['format'] === 'json', 'Retry must preserve the POST body and format.');
        check($retry['headers']['Authorization'] === 'Bearer new-token', 'Retry must use the refreshed token.');
        
        $integration = integration([[401, '[{"errorCode":"INVALID_SESSION_ID"}]']]);
        $integration->refreshedToken = null;
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === 'INVALID_SESSION_ID', 'Expected refresh failure to propagate.');
        check(count($integration->client->requests) === 1 && count($integration->errors) === 1, 'Refresh failure must be logged without another POST.');
        
        $integration = integration([[401, '[{"errorCode":"INVALID_SESSION_ID"}]'], [401, '[{"errorCode":"INVALID_SESSION_ID"}]']]);
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === 'INVALID_SESSION_ID', 'Expected a repeated authentication failure to propagate.');
        check($integration->refreshes === 1 && count($integration->client->requests) === 2, 'Must not refresh and replay more than once.');
        
        $integration = integration([]);
        $integration->token = null;
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === 'Cannot locate access token for request.', 'Expected an explicit missing-token error.');
        check($integration->client->requests === [] && count($integration->errors) === 1, 'A missing token must be logged without sending a request.');
        
        $integration = integration([[200, '[{"isSuccess":false,"errors":[{"statusCode":"FLOW_ERROR","message":"Flow failed"}]}]']]);
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === 'FLOW_ERROR: Flow failed', 'HTTP 200 flow failures must preserve error details.');
        check(count($integration->errors) === 1, 'Flow failures must be logged.');
        
        foreach ([
            [503, '{"message":"Unavailable"}', 'Request failed. HTTP Status: 503'],
            [200, '<html>Not JSON</html>', 'Unexpected non-JSON response from Salesforce.'],
            [200, '[]', 'Unexpected Salesforce flow invocation response.'],
            [200, '[{"isSuccess":true}]', 'Unexpected Salesforce flow invocation response.'],
        ] as [$status, $body, $expected]) {
            $integration = integration([[$status, $body]]);
            check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === $expected, 'Expected explicit HTTP/response errors.');
            check($integration->refreshes === 0 && count($integration->errors) === 1, 'Non-authentication failures must be logged and not retried.');
        }
        
        $waiting = $result;
        $waiting['outputValues']['Flow__InterviewStatus'] = 'Waiting';
        $integration = integration([[200, json_encode([$waiting], JSON_THROW_ON_ERROR)]]);
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']) === $waiting, 'Waiting must not be changed into Finished.');
        
        $integration = integration([[200, '{"sobjects":[{"name":"Contact"}]}'], [200, '{"records":[{"Id":"003"}]}']]);
        check((new ReflectionMethod(Salesforce::class, 'getObjects'))->invoke($integration) === [['name' => 'Contact']], 'Object GET must retain return-key extraction.');
        check((new ReflectionMethod(Salesforce::class, 'getWithSOQL'))->invoke($integration, 'Contact', 'Id') === [['Id' => '003']], 'SOQL GET must retain return-key extraction.');
        check($integration->client->requests[0]['method'] === 'GET' && $integration->client->requests[1]['method'] === 'GET', 'Existing queries must remain GET requests.');
        
        $parsedResult = $result;
        $parsedResult['outputValues'] += [
            'amount' => 12, 'enabled' => false, 'ids' => ['001', '002'], 'optional' => null, 'extra' => 'ignored',
        ];
        $integration = integration([[200, json_encode([$parsedResult], JSON_THROW_ON_ERROR)]]);
        $integration->flow['sf_flow_outputs_cache'] = [
            ['name' => 'amount', 'type' => 'NUMBER'],
            ['name' => 'enabled', 'type' => 'BOOLEAN'],
            ['name' => 'ids', 'type' => 'STRING', 'maxOccurs' => -1],
            ['name' => 'optional', 'type' => 'STRING'],
        ];
        $parsed = $integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello']);
        check($parsed['outputValues'] === ['amount' => 12.0, 'enabled' => false, 'ids' => ['001', '002'], 'optional' => null], 'Outputs must use the schema, preserve false/null/IDs, and normalize numbers.');
        
        foreach ([
            [['name' => 'amount', 'type' => 'NUMBER'], 'not-a-number'],
            [['name' => 'enabled', 'type' => 'BOOLEAN'], 'false'],
            [['name' => 'ids', 'type' => 'STRING', 'maxOccurs' => -1], [1]],
        ] as [$variable, $value]) {
            $invalidResult = $result;
            $invalidResult['outputValues'] = [$variable['name'] => $value];
            $integration = integration([[200, json_encode([$invalidResult], JSON_THROW_ON_ERROR)]]);
            $integration->flow['sf_flow_outputs_cache'] = [$variable];
            check(is_string($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello'])), 'Invalid output types must fail instead of being silently coerced.');
            check(count($integration->errors) === 1, 'Invalid output types must be logged.');
        }
        
        $integration = integration([]);
        $integration->flow['sf_flow_inputs_cache'][0]['required'] = true;
        check(is_string($integration->invokeFlow('MT_Website_Test', [], false)), 'Missing required inputs must fail before dispatch.');
        check($integration->client->requests === [], 'Invalid inputs must not invoke Salesforce.');
    }

    public function testQueueDispatchWorkersAndTransactionalPersistence(): void {
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'queue' => [
                'default' => 'sync',
                'connections' => ['sync' => ['driver' => 'sync']],
                'failed' => ['driver' => 'file'],
            ],
        ]));
        $serviceProvider = new \MM\Meros\Crm\ServiceProvider($container);
        (new ReflectionMethod($serviceProvider, 'beforeBoot'))->invoke($serviceProvider);
        check(config('queue.default') === 'sync' && config('queue.failed') === ['driver' => 'file'], 'CRM queue configuration must not change the app default or failed-job provider.');
        Queue::swap(new \Illuminate\Queue\QueueManager($container));
        Queue::fake();
        
        $result = [
            'actionName' => 'MT_Website_Test',
            'isSuccess' => true,
            'errors' => null,
            'outputValues' => ['Flow__InterviewStatus' => 'Finished', 'Flow__InterviewGuid' => 'interview-guid'],
        ];
        $successBody = json_encode([$result], JSON_THROW_ON_ERROR);
        Queue::fake();
        $integration = integration([]);
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello'], false) === ['queued' => true], 'Non-awaited invocation must acknowledge queueing.');
        check($integration->client->requests === [], 'Queue dispatch must not issue an inline HTTP request.');
        $jobs = Queue::pushed(InvokeSalesforceFlow::class);
        check($jobs->count() === 1, 'Expected exactly one queued job.');
        $job = unserialize(serialize($jobs->first()));
        check($job->inputs === ['testVar' => 'Hello'] && $job->environment === 'default', 'Serialized job must retain validated inputs and environment.');
        $worker = integration([[200, $successBody]]);
        $worker->flow['sf_flow_inputs_cache'] = [
            ['name' => 'oldVariable', 'type' => 'STRING', 'required' => false],
        ];
        $job->handle($worker);
        check((array) $worker->client->requests[0]['payload']['inputs'][0] === ['testVar' => 'Hello'], 'A worker with older cached metadata must send the validated queued inputs unchanged.');
        $integration->client->responses = [[200, $successBody]];
        $job->handle($integration);
        check(count($integration->client->requests) === 1 && Queue::pushed(InvokeSalesforceFlow::class)->count() === 1, 'Worker must invoke synchronously without recursive dispatch.');
        
        $job->environment = 'production';
        expectFailure(fn () => $job->handle($integration), 'environment changed');
        check(count($integration->client->requests) === 1, 'An environment mismatch must not invoke Salesforce.');
        $job->environment = 'default';
        $integration->client->responses = [[500, '{}']];
        expectFailure(fn () => $job->handle($integration), 'HTTP Status: 500');
        
        $unavailable = integration([]);
        $unavailable->flow = null;
        expectFailure(fn () => $job->handle($unavailable), 'no longer configured');
        check($unavailable->client->requests === [], 'A removed flow must not be invoked by the worker.');
        $disconnected = integration([]);
        $GLOBALS['enabledOptions'][$disconnected->enableSettingName] = false;
        expectFailure(fn () => $job->handle($disconnected), 'no active connection');
        check($disconnected->client->requests === [], 'A disabled integration must not be invoked by the worker.');
        
        foreach (['sync', 'null', 'deferred'] as $driver) {
            app('config')->set('queue.connections.meros_crm.driver', $driver);
            $integration = integration([]);
            check(is_string($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello'], false)), 'Non-durable queue drivers must fail explicitly.');
            check($integration->client->requests === [] && count($integration->errors) === 1, 'Unsupported queues must not silently run inline.');
        }
        app('config')->set('queue.connections.meros_crm.driver', 'database');
        
        $queue = Queue::getFacadeRoot();
        Queue::swap(new class {
            public function connection(string $name): static { return $this; }
            public function push(object $job): never { throw new RuntimeException('Queue backend unavailable'); }
        });
        $integration = integration([]);
        expectFailure(fn () => $integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello'], false), 'Queue backend unavailable');
        check($integration->client->requests === [], 'Failed dispatch must not fall back to an inline invocation.');
        Queue::swap($queue);
        
        $capsule = new \Illuminate\Database\Capsule\Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_']);
        $database = $capsule->getConnection();
        $migration = require dirname(__DIR__) . '/../src/database/tables/008_meros_crm_jobs/create_meros_crm_jobs_table.php';
        $migration->__init();
        $definition = (new ReflectionProperty(\MM\Meros\Contracts\Features\Data\TableCreator::class, 'definition'))->getValue($migration);
        $database->getSchemaBuilder()->create($migration->getTableName(), $definition->callback);
        $transactions = new \Illuminate\Database\DatabaseTransactionsManager();
        $database->setTransactionManager($transactions);
        $container->instance('db.transactions', $transactions);
        $manager = new \Illuminate\Queue\QueueManager($container);
        $manager->addConnector('database', fn () => new \Illuminate\Queue\Connectors\DatabaseConnector($capsule->getDatabaseManager()));
        Queue::swap($manager);
        $integration = integration([]);
        $database->beginTransaction();
        check($integration->invokeFlow('MT_Website_Test', ['testVar' => 'Hello'], false) === ['queued' => true], 'Dedicated database queue must work even when the app default is sync.');
        $databaseQueue = Queue::connection('meros_crm');
        check($databaseQueue->size() === 0, 'Jobs must not be inserted before the transaction commits.');
        $database->commit();
        check($databaseQueue->size() === 1 && $database->table('meros_crm_jobs')->first()->queue === 'meros_crm', 'Committed jobs must be stored in the dedicated table and queue.');
        $reserved = $databaseQueue->pop();
        check($reserved !== null && $reserved->attempts() === 1, 'Database queue must be able to reserve a job using the migration schema.');
        $queuedInvocation = unserialize($reserved->payload()['data']['command']);
        $integration->client->responses = [[200, $successBody]];
        $queuedInvocation->handle($integration);
        $reserved->delete();
        check($databaseQueue->size() === 0 && count($integration->client->requests) === 1, 'Persisted jobs must deserialize, invoke Salesforce, and be removable.');
        $database->beginTransaction();
        $integration->invokeFlow('MT_Website_Test', ['testVar' => 'Rolled back'], false);
        $database->rollBack();
        check($databaseQueue->size() === 0, 'Rolled-back transactions must not leave queued CRM jobs.');
        Queue::swap($queue);
    }

    public function testFlowActionControlsAndFormRunners(): void {
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'queue' => [
                'default' => 'sync',
                'connections' => ['sync' => ['driver' => 'sync']],
                'failed' => ['driver' => 'file'],
            ],
        ]));
        $serviceProvider = new \MM\Meros\Crm\ServiceProvider($container);
        (new ReflectionMethod($serviceProvider, 'beforeBoot'))->invoke($serviceProvider);
        check(config('queue.default') === 'sync' && config('queue.failed') === ['driver' => 'file'], 'CRM queue configuration must not change the app default or failed-job provider.');
        Queue::swap(new \Illuminate\Queue\QueueManager($container));
        Queue::fake();
        
        $result = [
            'actionName' => 'MT_Website_Test',
            'isSuccess' => true,
            'errors' => null,
            'outputValues' => ['Flow__InterviewStatus' => 'Finished', 'Flow__InterviewGuid' => 'interview-guid'],
        ];
        $successBody = json_encode([$result], JSON_THROW_ON_ERROR);
        $request = ['url' => 'https://example.my.salesforce.com/services/data/v67.0/actions/custom/flow/MT_Website_Test'];
        $provider = new class implements FeatureProvider {
            public function get(): static { return $this; }
            public function getHandle(bool $slug = false): string { return 'tests'; }
            public function getName(): string { return 'Tests'; }
            public function getPath(string $subPath = ''): string { return dirname(__DIR__) . '/' . $subPath; }
            public function getUri(string $subPath = ''): string { return $subPath; }
            public function getPreference(string $key, bool $fullPath = true): mixed { return null; }
            public function resolveSettingsContainer(SettingsContainers $register): SettingsContainer {
                throw new LogicException('Settings containers are not used in this test.');
            }
        };
        Registers::swap(new RegistersRegistry());
        $register = new FlowActionsRegister();
        FlowActions::swap($register);
        $integration = integration([[200, $successBody], [200, $successBody]]);
        SalesforceFacade::swap($integration);
        $orchestrator = (new ReflectionClass(Orchestrator::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(\MM\Meros\Contracts\Orchestrator::class, 'provider'))->setValue($orchestrator, $provider);
        (new ReflectionMethod(Orchestrator::class, 'configureSalesforceFlowActions'))->invoke($orchestrator);
        $action = $register->get('mt_website_test');
        check($action instanceof FlowAction, 'Expected a registered Salesforce action.');
        check($action->getInputSchema()['await_response']['default'] === true && $action->getInputSchema()['await_response']['field_type'] === 'checkbox', 'Await control must default to synchronous invocation.');
        check($action->getOutputSchema()['Flow__InterviewStatus']['type'] === 'string', 'Cached outputs must be available to subsequent actions with PHP types.');
        
        $fields = new \MM\Meros\Registers\Components\Fields();
        \MM\Meros\Facades\Components\Fields::swap($fields);
        $fields->checkout($provider)->register(\MM\Meros\App\Components\Fields\Checkbox::class, 'checkbox');
        $fields->checkout($provider)->register(\MM\Meros\App\Components\Fields\Number::class, 'number');
        $fields->checkout($provider)->register(\MM\Meros\App\Components\Fields\Select::class, 'select');
        $fields->checkout($provider)->register(\MM\Meros\App\Components\Fields\Text::class, 'text');
        $controls = (new ReflectionClass(FlowControls::class))->newInstanceWithoutConstructor();
        $buildValueRow = new ReflectionMethod(FlowControls::class, 'buildInputValueRow');
        $row = FieldRow::make($provider);
        $buildValueRow->invoke($controls, $row, 'await_response', $action->getInputSchema()['await_response'], [], []);
        check($row->getField(0)->getDefaultValue() === true, 'Rendered await control must default to true, not queue jobs by default.');
        $row = FieldRow::make($provider);
        $buildValueRow->invoke($controls, $row, 'amount', [
            'type' => 'integer|double', 'field_type' => 'number',
        ], [
            ['name' => 'sf_amount', 'label' => 'Salesforce amount', 'data_type' => 'integer|double'],
            ['name' => 'count', 'label' => 'Count', 'data_type' => 'integer'],
            ['name' => 'text', 'label' => 'Text', 'data_type' => 'string'],
        ], []);
        check($row->getField(1)->toArray()['options'] === ['sf_amount' => 'Salesforce amount', 'count' => 'Count'], 'Numeric union inputs must offer compatible output references in the controls.');
        $row = FieldRow::make($provider);
        (new ReflectionMethod(FlowControls::class, 'buildInputConfigRow'))->invoke($controls, $row, 'firstName', ['type' => 'string']);
        check($row->getField(0)->getName() === 'first_name_label' && $row->getField(0)->getDefaultValue() === 'firstName', 'Editor control names must be normalized without changing the displayed API variable name.');
        $row = FieldRow::make($provider);
        $buildValueRow->invoke($controls, $row, 'firstName', ['type' => 'string'], [], []);
        foreach ($row->getFields() as $field) {
            check($field->getConditions()['show']['rules'][0]['field'] === 'use_dynamic_value_first_name', 'Static and dynamic controls must reference the actual normalized toggle name.');
        }
        
        $actions = [[
            'action' => 'mt_website_test', 'action_unique_id' => 'salesforce',
            '__form_data' => json_encode([
                'action_inputs' => [
                    ['input_name' => 'testVar', 'input_value' => 'Hello', 'use_dynamic_value' => false],
                    ['input_name' => 'await_response', 'input_value' => true, 'use_dynamic_value' => false],
                ],
            ]),
        ]];
        $register->checkout($provider)->make(function (FlowAction $action) {
            $action->name('consume_output');
            $action->addInput('status', 'string');
            $action->runner(function (array $input): FlowActionResponse {
                check($input['status'] === 'Finished', 'Later actions must receive awaited outputs.');
                return new FlowActionResponse([]);
            });
        });
        $actions[] = [
            'action' => 'consume_output', 'action_unique_id' => 'consumer',
            '__form_data' => json_encode([
                'action_inputs' => [
                    ['input_name' => 'status', 'input_value' => '{{Flow__InterviewStatus_source_salesforce}}', 'use_dynamic_value' => true],
                ],
            ]),
        ];
        $runner = new FlowRunner($actions, []);
        check($runner->run(), 'Salesforce action must run and expose outputs to subsequent actions: ' . $runner->getLastError());
        $formRunner = new FormActionsRunner(1, 2, $actions, []);
        check($formRunner->run(), 'Form actions must also receive awaited Salesforce outputs: ' . $formRunner->getLastError());
        check($integration->client->requests[0]['url'] === $request['url'], 'Orchestrator must invoke the original API name, not the humanized label.');
        check(json_encode($integration->client->requests[0]['payload']) === '{"inputs":[{"testVar":"Hello"}]}', 'Meros await control must not reach Salesforce.');
        $response = $action->run(['testVar' => 'Hello', 'await_response' => false]);
        check(!$response->hasError() && $response->output() === [], 'Queued actions must not expose Salesforce outputs.');
        $integration->client->responses = [[403, '[{"errorCode":"INSUFFICIENT_ACCESS"}]']];
        check($action->run(['testVar' => 'Hello', 'await_response' => true])->hasError(), 'Salesforce errors must become action errors.');
        
        $contactResult = $result;
        $contactResult['outputValues']['contactId'] = '003000000000001AAA';
        $integration = integration([
            [200, json_encode([$contactResult], JSON_THROW_ON_ERROR)],
            [200, json_encode([$contactResult], JSON_THROW_ON_ERROR)],
        ]);
        $integration->flow['sf_flow_inputs_cache'] = array_map(
            fn ($name) => ['name' => $name, 'type' => 'STRING', 'required' => true],
            ['firstName', 'lastName', 'email']
        );
        $integration->flow['sf_flow_outputs_cache'][] = ['name' => 'contactId', 'type' => 'STRING'];
        SalesforceFacade::swap($integration);
        Registers::swap(new RegistersRegistry());
        $register = new FlowActionsRegister();
        FlowActions::swap($register);
        (new ReflectionMethod(Orchestrator::class, 'configureSalesforceFlowActions'))->invoke($orchestrator);
        $contactAction = $register->get('mt_website_test');
        $savedConfig = [
            'action' => 'mt_website_test', 'action_unique_id' => 'mt_website_test_0',
            '__form_data' => json_encode([
                'action_inputs' => [
                    ['input_name' => 'firstName', 'input_value' => '{{first_name}}', 'use_dynamic_value' => true],
                    ['input_name' => 'lastName', 'input_value' => '{{last_name}}', 'use_dynamic_value' => true],
                    ['input_name' => 'email', 'input_value' => '{{email}}', 'use_dynamic_value' => true],
                    ['input_name' => 'await_response', 'input_value' => true, 'use_dynamic_value' => false],
                ],
                'error_behaviour' => 'continue',
            ], JSON_THROW_ON_ERROR),
        ];
        $triggerPayload = ['data' => [
            'first_name' => ['user_value' => 'Ada'],
            'last_name' => ['user_value' => 'Lovelace'],
            'email' => ['user_value' => 'ada@example.test'],
        ]];
        foreach ([new FlowRunner([$savedConfig], $triggerPayload), new FormActionsRunner(263, 32, [$savedConfig], $triggerPayload)] as $runner) {
            check($runner->run(), 'Persisted camelCase API inputs must resolve normalized editor keys: ' . $runner->getLastError());
            $completed = $runner->getCompletedActions()['mt_website_test_0'];
            check($completed['inputs'] === ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.test', 'await_response' => true], 'Completed inputs must retain original Salesforce variable names.');
            check($completed['outputs']['contactId'] === '003000000000001AAA', 'Returned contact IDs must reach action outputs.');
        }
        check((array) $integration->client->requests[0]['payload']['inputs'][0] === [
            'firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.test',
        ], 'Salesforce must receive its case-sensitive variable names, without the await control.');
        
        Queue::fake();
        $backgroundConfig = $savedConfig;
        $backgroundSettings = json_decode($savedConfig['__form_data'], true, flags: JSON_THROW_ON_ERROR);
        $backgroundSettings['action_inputs'][3]['input_value'] = false;
        $backgroundConfig['__form_data'] = json_encode($backgroundSettings, JSON_THROW_ON_ERROR);
        foreach ([new FlowRunner([$backgroundConfig], $triggerPayload), new FormActionsRunner(263, 32, [$backgroundConfig], $triggerPayload)] as $runner) {
            check($runner->run(), 'Non-awaited contact actions must resolve dynamic inputs before dispatch: ' . $runner->getLastError());
            check($runner->getCompletedActions()['mt_website_test_0']['outputs'] === [], 'Non-awaited contact actions must not expose outputs.');
            $job = unserialize(serialize(Queue::pushed(InvokeSalesforceFlow::class)->last()));
            $worker = integration([[200, json_encode([$contactResult], JSON_THROW_ON_ERROR)]]);
            $job->handle($worker);
            check(json_encode($worker->client->requests[0]['payload'], JSON_THROW_ON_ERROR) === json_encode($integration->client->requests[0]['payload'], JSON_THROW_ON_ERROR), 'Queued contact inputs must exactly match the awaited payload, even with an older worker schema.');
        }
        check(count($integration->client->requests) === 2, 'Non-awaited contact actions must not issue inline HTTP calls.');
        
        $parseInputs = new ReflectionMethod(FlowRunner::class, 'getActionInputPayload');
        $payload = [
            'action_inputs' => [
                ['input_name' => 'firstName', 'input_value' => 'Static name', 'use_dynamic_value' => false],
                ['input_name' => 'lastName', 'input_value' => 'Lovelace', 'use_dynamic_value' => false],
                ['input_name' => 'email', 'input_value' => 'ada@example.test', 'use_dynamic_value' => false],
                ['input_name' => 'await_response', 'input_value' => false, 'use_dynamic_value' => false],
            ],
        ];
        foreach ([$payload, json_encode($payload, JSON_THROW_ON_ERROR)] as $formData) {
            $parsed = $parseInputs->invoke(new FlowRunner([], []), $contactAction, ['__form_data' => $formData]);
            check(is_array($parsed) && $parsed['firstName'] === 'Static name' && $parsed['await_response'] === false, 'Static row inputs must preserve case-sensitive names and false values for decoded and JSON configurations.');
        }
        $payload['action_inputs'][0]['use_dynamic_value'] = true;
        $payload['action_inputs'][0]['input_value'] = '{{first_name_source_missing}}';
        $parsed = $parseInputs->invoke(new FlowRunner([], []), $contactAction, ['__form_data' => json_encode($payload)]);
        check(is_string($parsed) && str_contains($parsed, 'firstName'), 'An invalid dynamic input must still fail rather than falling back to a static value.');
    }

}
