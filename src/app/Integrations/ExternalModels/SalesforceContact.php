<?php

namespace MM\Meros\Crm\App\Integrations\ExternalModels;

/**
 * Salesforce Contact model-style external object.
 */
class SalesforceContact extends SalesforceObject {
    protected function objectName(): string {
        return 'Contact';
    }
}
