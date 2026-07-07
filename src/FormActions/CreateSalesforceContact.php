<?php

namespace MM\Meros\Crm\FormActions;

use MM\Meros\Crm\Integrations\ExternalModels\SalesforceContacts;
use MM\Meros\Services\Contracts\Forms\FormAction;
use MM\Meros\Support\MergeFields;

use MM\Meros\Facades\FieldGroups;
use MM\Meros\Facades\Framework;

final class CreateSalesforceContact extends FormAction {
    public string $handle  = 'create_salesforce_contact';

    public string $label       = 'Create Salesforce contact';

    public string $description = 'Creates a Salesforce Contact using mapped form values and merge fields.';

    public function execute(array $submission, array $context = []): mixed {
        $firstName = $this->resolveMappedValue('first_name_source', $submission);
        $lastName = $this->resolveMappedValue('last_name_source', $submission);
        $email = $this->resolveMappedValue('email_source', $submission);

        if ($lastName === '') {
            throw new \RuntimeException('Salesforce contact action requires a Last Name value.');
        }

        $payload = [
            'LastName' => $lastName,
        ];

        if ($firstName !== '') {
            $payload['FirstName'] = $firstName;
        }

        if ($email !== '') {
            $payload['Email'] = $email;
        }

        $connectionLabel = trim((string) ($this->config['connection_label'] ?? ''));

        /** @var SalesforceContacts $salesforce */
        $salesforce = app(SalesforceContacts::class);

        if ($connectionLabel !== '') {
            $salesforce->usingConnection($connectionLabel);
        }

        return $salesforce->createContact($payload);
    }

    public function renderConfigurationDialog(array $formFields, array $currentConfig): string {
        $sourceOptions = $this->buildSourceOptions($formFields);

        $fieldGroup = FieldGroups::checkout(Framework::get())->make(function ($fieldGroup) use ($sourceOptions, $currentConfig) {
            $fieldGroup->id('action-create-salesforce-contact-config');
            $fieldGroup->title('Salesforce Contact Configuration');

            $fieldGroup->field('text')
                ->id('connection_label')
                ->name('connection_label')
                ->label('Connection Label')
                ->helpText('Optional label for a specific Salesforce connection. Leave blank to use the first active connection.')
                ->default($currentConfig['connection_label'] ?? '');

            $fieldGroup->field('select')
                ->id('first_name_source')
                ->name('first_name_source')
                ->label('First Name Source')
                ->options($sourceOptions)
                ->default($currentConfig['first_name_source'] ?? '');

            $fieldGroup->field('select')
                ->id('last_name_source')
                ->name('last_name_source')
                ->label('Last Name Source')
                ->options($sourceOptions)
                ->default($currentConfig['last_name_source'] ?? '');

            $fieldGroup->field('select')
                ->id('email_source')
                ->name('email_source')
                ->label('Email Source')
                ->options($sourceOptions)
                ->default($currentConfig['email_source'] ?? '');
        });

        return $fieldGroup->html();
    }

    private function buildSourceOptions(array $formFields): array {
        $options = [
            '' => 'Select a source...',
        ];

        foreach ($formFields as $fieldName => $fieldLabel) {
            $options['form:' . $fieldName] = 'Form: ' . $fieldLabel;
        }

        $mergeOptions = MergeFields::get()->toOptions('string');

        foreach ($mergeOptions as $mergeKey => $mergeLabel) {
            $options['merge:' . $mergeKey] = 'Merge: ' . $mergeLabel;
        }

        return $options;
    }

    private function resolveMappedValue(string $configKey, array $submission): string {
        $mapping = trim((string) ($this->config[$configKey] ?? ''));

        if ($mapping === '') {
            return '';
        }

        if (str_starts_with($mapping, 'form:')) {
            $fieldName = substr($mapping, 5);
            $value = $submission[$fieldName] ?? '';

            return is_scalar($value) ? trim((string) $value) : '';
        }

        if (str_starts_with($mapping, 'merge:')) {
            $mergeKey = substr($mapping, 6);
            $resolved = MergeFields::get()->resolve($mergeKey, 'string');

            return is_scalar($resolved) ? trim((string) $resolved) : '';
        }

        return $mapping;
    }
}
