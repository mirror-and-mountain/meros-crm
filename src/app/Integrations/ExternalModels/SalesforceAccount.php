<?php

namespace MM\Meros\Crm\App\Integrations\ExternalModels;

/**
 * Salesforce Account model-style external object.
 */
class SalesforceAccount extends SalesforceObject {
    protected function objectName(): string {
        return 'Account';
    }
}
