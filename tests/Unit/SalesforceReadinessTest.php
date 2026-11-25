<?php

namespace MM\Meros\Crm\Tests\Unit;

use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\Crm\App\Integrations\Salesforce;
use MM\Meros\Crm\Tests\Support\SalesforceReadinessDouble;
use MM\Meros\Crm\Tests\Support\SalesforceReadinessSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests rejection and logging of disabled Salesforce requests and missing-token failure behavior.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesforceReadinessTest extends TestCase {
    private SalesforceReadinessDouble $salesforce;

    protected function setUp(): void {
        require_once dirname(__DIR__) . '/Support/SalesforceReadiness.php';
        $settings = (new ReflectionClass(SalesforceReadinessSettings::class))->newInstanceWithoutConstructor();
        $settings->initialiseForTest();
        $this->salesforce = (new ReflectionClass(SalesforceReadinessDouble::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(Integration::class, 'identifier'))->invoke($this->salesforce, 'name', 'snake');
        (new ReflectionProperty(Integration::class, 'name'))->setValue($this->salesforce, 'salesforce_test');
        (new ReflectionProperty(Integration::class, 'settings'))->setValue($this->salesforce, $settings);
        (new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($this->salesforce, $settings);
        $GLOBALS['options']['salesforce_readiness_options'] = ['salesforce_test_enabled' => true];
    }

    public function testDisabledRequestsAreRejectedAndLogged(): void {
        self::assertTrue($this->salesforce->ready());
        $GLOBALS['options']['salesforce_readiness_options']['salesforce_test_enabled'] = false;
        self::assertFalse($this->salesforce->ready());
        self::assertNull($this->salesforce->get(false, false));
        $request = new ReflectionMethod(Salesforce::class, 'request');
        self::assertStringContainsString('disabled', $request->invoke($this->salesforce, 'https://example.test', []));
        self::assertCount(2, $this->salesforce->errors);
    }

    public function testMissingTokenPreservesFailureContract(): void {
        $this->salesforce->token = null;
        self::assertFalse($this->salesforce->ready());
        self::assertSame('Cannot locate access token for request.', $this->salesforce->invokeFlow('Test', []));
    }
}
