<?php

namespace MM\Meros\Crm;

use MM\Meros\App\Package;
use MM\Meros\Crm\App\DynamicChoiceSources\SalesforceAccounts;
use MM\Meros\Crm\App\DynamicChoiceSources\SalesforceContacts;
use MM\Meros\Crm\App\DynamicChoiceSources\SalesforceObjectFields;
use MM\Meros\Crm\App\DynamicChoiceSources\SalesforceObjectRecords;
use MM\Meros\Crm\App\DynamicChoiceSources\SalesforceObjects;
use MM\Meros\Crm\App\Integrations\Salesforce;

class MerosCrm extends Package {
    public string $name = 'Meros CRM';

    public string $author = 'Meros';

    public string $handle = 'meros_crm';

    public string $authorUri = 'https://mirrorandmountain.com';

    public string $authorSupportUri = 'https://mirrorandmountain.com';

    public string $description = 'Salesforce-specific CRM integrations for Meros.';

    protected function load(): void {
        $this->integrations()->register('salesforce', Salesforce::class);
        $this->dynamicChoiceSources()->register('salesforce_contacts', SalesforceContacts::class);
        $this->dynamicChoiceSources()->register('salesforce_accounts', SalesforceAccounts::class);
        $this->dynamicChoiceSources()->register('salesforce_objects', SalesforceObjects::class);
        $this->dynamicChoiceSources()->register('salesforce_object_fields', SalesforceObjectFields::class);
        $this->dynamicChoiceSources()->register('salesforce_object_records', SalesforceObjectRecords::class);
    }

    protected function configure(): void {
        $this->settings()->add(function ($setting) {
            $setting->array('contact_data_fields')
                ->label('Contact Data Fields')
                ->field('repeater', function ($field) {
                    $field->subField('text')->name('field_name')->label('Field Name');
                    $field->subField('text')->name('field_label')->label('Field Label');
                    $field->subField('multi_select', function ($subField) {
                        $subField->name('test_crm_lookup');
                        $subField->label('Test CRM Lookup');
                        $subField->dynamicOptionsSource('salesforce_object_fields');
                        $subField->dynamicOptionsConfig([
                            'objectApiName' => 'contact', // e.g. Contact, Account, Lead
                            'connection' => 'default',    // optional; '' uses default active connection
                            'limit' => 100,               // optional; source caps at 200
                        ]);
                    });

                    $field->default([
                        [
                            'field_name' => 'first_name',
                            'field_label' => 'First Name',
                        ],
                        [
                            'field_name' => 'last_name',
                            'field_label' => 'Last Name',
                        ],
                        [
                            'field_name' => 'email',
                            'field_label' => 'Email',
                        ],
                    ]);
                });
        });
    }

    public function getContactFields(): array {
        return $this->settings('contact_data_fields')->getValue() ?? [];
    }
}
