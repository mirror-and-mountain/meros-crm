<?php

namespace MM\Meros\Crm\FormActions;

use MM\Meros\App\Integrations\ExternalModels\GenericResource;
use MM\Meros\Crm\Support\Integrations\CRM\SyncJob;
use MM\Meros\Crm\Support\Integrations\CRM\SyncJobRunner;
use MM\Meros\Services\Contracts\Forms\FormAction;
use MM\Meros\Support\MergeFields;

use MM\Meros\Facades\FieldGroups;
use MM\Meros\Facades\Framework;
use MM\Meros\Facades\Integrations;

/**
 * Class RunCrmSyncJobs
 *
 * This class represents a form action that runs one or more CRM synchronization jobs based on the provided configuration.
 * It allows for flexible mapping of form values and merge fields to CRM objects and endpoints.
 *
 * @package MM\Meros\Crm\FormActions
 */
final class RunCrmSyncJobs extends FormAction {
    public string $handle = 'run_crm_sync_jobs';
    public string $label  = 'Run CRM sync jobs';
    public string $description = 'Runs one or more CRM sync jobs from mapping rows. Use this for configurable payloads; use explicit actions for strict object workflows.';

    /**
     * Executes the configured CRM synchronization jobs based on the provided submission data and context.
     *
     * @param array $submission The submission data used to resolve values for the sync jobs.
     * @param array $context Optional context data for the sync jobs.
     *
     * @return mixed
     */
    public function execute(array $submission, array $context = []): mixed {
        $jobs = is_array($this->config['jobs'] ?? null) ? $this->config['jobs'] : [];

        if ($jobs === []) {
            return [];
        }

        $syncJobs = [];

        foreach ($jobs as $jobData) {
            if (!is_array($jobData)) {
                continue;
            }

            $job = SyncJob::fromArray($jobData);

            if ($job->isValid()) {
                $syncJobs[] = $job;
            }
        }

        if ($syncJobs === []) {
            return [];
        }

        /** @var SyncJobRunner $runner */
        $runner = app(SyncJobRunner::class);

        return $runner->runMany($syncJobs, $submission, $context);
    }

    /**
     * Renders the configuration dialog for the CRM sync jobs action.
     *
     * @param array $formFields The available form fields for mapping.
     * @param array $currentConfig The current configuration of the action.
     *
     * @return string The HTML output of the configuration dialog.
     */
    public function renderConfigurationDialog(array $formFields, array $currentConfig): string {
        $crmIntegrations = $this->getCrmIntegrationOptions();
        $sourceOptions   = $this->buildSourceOptions($formFields);

        $jobsDefault = is_array($currentConfig['jobs'] ?? null) ? $currentConfig['jobs'] : [];

        if ($jobsDefault === []) {
            $defaultIntegration = array_key_exists('salesforce', $crmIntegrations)
                ? 'salesforce'
                : (array_key_first($crmIntegrations) ?? '');

            if ($defaultIntegration !== '') {
                $jobsDefault = [[
                    'integration_handle' => $defaultIntegration,
                    'object'             => $defaultIntegration === 'salesforce' ? 'sobjects/Contact' : '',
                    'method'             => 'POST',
                    'endpoint'           => '',
                    'connection_label'   => '',
                    'mappings' => [[
                        'target_field' => 'LastName',
                        'source'       => 'merge:user_lastname',
                        'fallback'     => 'Website Lead',
                    ]],
                ]];
            }
        }

        [$salesforceObjectOptions, $salesforceFieldOptionsByObject] = $this->getSalesforceSchemaOptions($jobsDefault, array_key_exists('salesforce', $crmIntegrations));

        $fieldGroup = FieldGroups::checkout(Framework::get())->make(function ($fieldGroup) use ($crmIntegrations, $sourceOptions, $jobsDefault, $salesforceObjectOptions, $salesforceFieldOptionsByObject) {
            $fieldGroup->id('action-run-crm-sync-jobs-config');
            $fieldGroup->title('CRM Sync Jobs Configuration');
            $fieldGroup->description('Use this action for flexible, admin-configured writes. For strict Salesforce contact flows, prefer the dedicated Create Salesforce contact action.');

            $fieldGroup->field('repeater', function ($jobsRepeater) use ($crmIntegrations, $sourceOptions, $jobsDefault, $salesforceObjectOptions, $salesforceFieldOptionsByObject) {
                $jobsRepeater->id('jobs');
                $jobsRepeater->name('jobs');
                $jobsRepeater->label('Sync Jobs');
                $jobsRepeater->helpText('Flexible mapper for one or more CRM writes. If your workflow is specifically Salesforce Contact creation, use the dedicated action instead.');
                $jobsRepeater->allowConfigure(true);
                $jobsRepeater->allowAdd(true);
                $jobsRepeater->allowRemove(true);
                $jobsRepeater->allowReorder(true);
                $jobsRepeater->configureRequiredFields(['integration_handle', 'object']);
                $jobsRepeater->addRowText('Add Sync Job');
                $jobsRepeater->configureRowText('Configure Job');
                $jobsRepeater->removeRowText('Remove Job');

                $jobsRepeater->field('select')
                    ->id('integration_handle')
                    ->name('integration_handle')
                    ->label('CRM Integration')
                    ->options(array_merge(['' => 'Select integration...'], $crmIntegrations));

                $jobsRepeater->field('text')
                    ->id('object')
                    ->name('object')
                    ->label('Object / Resource')
                    ->helpText('Examples: sobjects/Contact, contacts, leads');

                foreach ($salesforceFieldOptionsByObject as $salesforceObjectPath => $targetFieldOptions) {
                    $this->registerSyncJobDialog(
                        $jobsRepeater,
                        $crmIntegrations,
                        $sourceOptions,
                        $salesforceObjectOptions,
                        $targetFieldOptions,
                        $salesforceFieldOptionsByObject,
                        ['object', '=', $salesforceObjectPath],
                        true
                    );
                }

                if ($salesforceObjectOptions !== []) {
                    $this->registerSyncJobDialog(
                        $jobsRepeater,
                        $crmIntegrations,
                        $sourceOptions,
                        $salesforceObjectOptions,
                        [],
                        $salesforceFieldOptionsByObject,
                        ['integration_handle', '=', 'salesforce'],
                        true
                    );
                }

                $this->registerSyncJobDialog(
                    $jobsRepeater,
                    $crmIntegrations,
                    $sourceOptions,
                    [],
                    [],
                    [],
                    ['integration_handle', '!=', ''],
                    false
                );

                $jobsRepeater->default($jobsDefault);
            });
        });

        return $fieldGroup->html();
    }

    /**
     * Retrieves the available CRM integration options for the configuration dialog.
     *
     * @return array An associative array of CRM integration handles and their labels.
     */
    private function getCrmIntegrationOptions(): array {
        $options = [];

        foreach (Integrations::checkout(Framework::get())->getRegistered() as $handle => $_) {
            $integration = Integrations::checkout(Framework::get())->makeFrom($handle);

            if ($integration->getCategory() !== 'crm') {
                continue;
            }

            $options[$handle] = $integration->getLabel();
        }

        return $options;
    }

    /**
     * Builds the source options for the configuration dialog based on available form fields and merge fields.
     *
     * @param array $formFields The available form fields for mapping.
     *
     * @return array An associative array of source options for the configuration dialog.
     */
    private function buildSourceOptions(array $formFields): array {
        $options = [
            '' => 'Select a source...',
        ];

        foreach ($formFields as $fieldName => $fieldLabel) {
            $options['form:' . $fieldName] = 'Form: ' . $fieldLabel;
        }

        foreach (MergeFields::get()->toOptions('string') as $mergeKey => $mergeLabel) {
            $options['merge:' . $mergeKey] = 'Merge: ' . $mergeLabel;
        }

        $options['value:'] = 'Static Value (prefix with value:)';

        return $options;
    }

    /**
     * Registers a custom configuration dialog for a sync job within the jobs repeater.
     *
     * @param mixed $jobsRepeater The jobs repeater field instance.
     * @param array $crmIntegrations The available CRM integration options.
     * @param array $sourceOptions The available source options for field mappings.
     * @param array $objectOptions The available object/resource options for Salesforce.
     * @param array $targetFieldOptions The available target field options for Salesforce.
     * @param array $targetFieldOptionsByObject The target field options grouped by object/resource.
     * @param array $rule The rule to determine when to show this dialog.
     * @param bool  $isSalesforceDialog Whether this dialog is specific to Salesforce integrations.
     */
    private function registerSyncJobDialog(
        $jobsRepeater,
        array $crmIntegrations,
        array $sourceOptions,
        array $objectOptions,
        array $targetFieldOptions,
        array $targetFieldOptionsByObject,
        array $rule,
        bool $isSalesforceDialog
    ): void {
        $jobsRepeater->customConfigurationDialog(function ($dialog) use ($crmIntegrations, $sourceOptions, $objectOptions, $targetFieldOptions, $targetFieldOptionsByObject, $isSalesforceDialog) {
            $dialog->id('sync-job-config-dialog');
            $dialog->title('Sync Job Details');

            $dialog->field('select')
                ->id('integration_handle')
                ->name('integration_handle')
                ->label('CRM Integration')
                ->options(array_merge(['' => 'Select integration...'], $crmIntegrations));

            if ($isSalesforceDialog && $objectOptions !== []) {
                $dialog->field('select')
                    ->id('object')
                    ->name('object')
                    ->label('Object / Resource')
                    ->options(array_merge(['' => 'Select Salesforce object...'], $objectOptions))
                    ->helpText('Live Salesforce schema detected. Pick an object to map fields.');

                $dialog->field('hidden')
                    ->id('__target_field_options_by_object')
                    ->name('__target_field_options_by_object')
                    ->default(json_encode($targetFieldOptionsByObject))
                    ->attribute('value-as-json', 'true');
            } else {
                $dialog->field('text')
                    ->id('object')
                    ->name('object')
                    ->label('Object / Resource')
                    ->helpText('Examples: sobjects/Contact, contacts, leads');
            }

            $dialog->field('select')
                ->id('method')
                ->name('method')
                ->label('HTTP Method')
                ->options([
                    'POST' => 'POST',
                    'PUT' => 'PUT',
                    'PATCH' => 'PATCH',
                ])
                ->default('POST');

            $dialog->field('text')
                ->id('endpoint')
                ->name('endpoint')
                ->label('Endpoint Override')
                ->helpText('Optional. If blank, object/resource value is used as the endpoint.');

            $dialog->field('text')
                ->id('connection_label')
                ->name('connection_label')
                ->label('Connection Label')
                ->helpText('Optional. Uses first active connection if blank.');

            $dialog->field('select')
                ->id('environment')
                ->name('environment')
                ->label('Environment')
                ->options([
                    '' => 'Default / First Active',
                    'production' => 'Production',
                    'sandbox' => 'Sandbox',
                    'test' => 'Test',
                    'live' => 'Live',
                ])
                ->default('');

            $dialog->field('repeater', function ($mappingsRepeater) use ($sourceOptions, $targetFieldOptions, $isSalesforceDialog, $objectOptions) {
                $mappingsRepeater->id('mappings');
                $mappingsRepeater->name('mappings');
                $mappingsRepeater->label('Field Mappings');
                $mappingsRepeater->allowConfigure(false);
                $mappingsRepeater->allowAdd(true);
                $mappingsRepeater->allowRemove(true);
                $mappingsRepeater->allowReorder(true);
                $mappingsRepeater->addRowText('Add Mapping');

                if ($isSalesforceDialog && $objectOptions !== []) {
                    $mappingsRepeater->field('select')
                        ->id('target_field')
                        ->name('target_field')
                        ->label('Target Field')
                        ->options(array_merge(['' => 'Select Salesforce field...'], $targetFieldOptions))
                        ->helpText('Salesforce schema detected. Target options refresh when Object / Resource changes.');
                } elseif ($targetFieldOptions !== []) {
                    $mappingsRepeater->field('select')
                        ->id('target_field')
                        ->name('target_field')
                        ->label('Target Field')
                        ->options(array_merge(['' => 'Select target field...'], $targetFieldOptions));
                } else {
                    $mappingsRepeater->field('text')
                        ->id('target_field')
                        ->name('target_field')
                        ->label('Target Field')
                        ->helpText($isSalesforceDialog
                            ? 'Enter a Salesforce field API name (for example LastName). Save object, then re-open Configure Job to auto-load field options when schema is available.'
                            : 'Target field key in the destination object.');
                }

                $mappingsRepeater->field('select')
                    ->id('source')
                    ->name('source')
                    ->label('Source')
                    ->options($sourceOptions);

                $mappingsRepeater->field('text')
                    ->id('fallback')
                    ->name('fallback')
                    ->label('Fallback')
                    ->helpText('Optional fallback when source is empty.');
            });
        }, $rule);
    }

    /**
     * Retrieves the Salesforce schema options for objects and fields, with caching support.
     *
     * @param array $jobsDefault The default jobs configuration to determine which objects to describe.
     * @param bool  $hasSalesforceIntegration Whether the Salesforce integration is available.
     *
     * @return array An array containing two elements: the object options and the field options by object.
     */
    private function getSalesforceSchemaOptions(array $jobsDefault, bool $hasSalesforceIntegration): array {
        if (!$hasSalesforceIntegration) {
            return [[], []];
        }

        if (function_exists('get_transient')) {
            $cached = get_transient('meros_crm_salesforce_schema_options');

            if (is_array($cached)) {
                return [
                    is_array($cached['objects'] ?? null) ? $cached['objects'] : [],
                    is_array($cached['fields_by_object'] ?? null) ? $cached['fields_by_object'] : [],
                ];
            }
        }

        $objectOptions = [];
        $fieldOptionsByObject = [];

        try {
            /** @var GenericResource $resource */
            $resource = app(GenericResource::class);

            $objectsResponse = $resource
                ->integration('salesforce')
                ->usingEnvironment('production')
                ->asJson()
                ->get('sobjects');

            if ($objectsResponse->ok()) {
                $sobjects = $objectsResponse->json('sobjects');

                if (is_array($sobjects)) {
                    foreach ($sobjects as $object) {
                        if (!is_array($object)) {
                            continue;
                        }

                        $name = trim((string) ($object['name'] ?? ''));

                        if ($name === '') {
                            continue;
                        }

                        $path = 'sobjects/' . $name;
                        $label = trim((string) ($object['label'] ?? $name));
                        $objectOptions[$path] = $label . ' (' . $path . ')';
                    }
                }
            }

            $objectsToDescribe = ['sobjects/Contact'];

            foreach ($jobsDefault as $job) {
                if (!is_array($job)) {
                    continue;
                }

                if (trim((string) ($job['integration_handle'] ?? '')) !== 'salesforce') {
                    continue;
                }

                $path = trim((string) ($job['object'] ?? ''));

                if ($path === '') {
                    continue;
                }

                $objectsToDescribe[] = $path;
            }

            $objectsToDescribe = array_values(array_unique(array_filter($objectsToDescribe, function ($path) {
                return is_string($path) && str_starts_with($path, 'sobjects/');
            })));

            foreach ($objectsToDescribe as $objectPath) {
                $describePath = rtrim($objectPath, '/') . '/describe';

                $describeResponse = $resource
                    ->integration('salesforce')
                    ->usingEnvironment('production')
                    ->asJson()
                    ->get($describePath);

                if (!$describeResponse->ok()) {
                    continue;
                }

                $fields = $describeResponse->json('fields');

                if (!is_array($fields)) {
                    continue;
                }

                $fieldOptions = [];

                foreach ($fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $apiName = trim((string) ($field['name'] ?? ''));

                    if ($apiName === '') {
                        continue;
                    }

                    $label = trim((string) ($field['label'] ?? $apiName));
                    $fieldOptions[$apiName] = $label . ' (' . $apiName . ')';
                }

                if ($fieldOptions !== []) {
                    $fieldOptionsByObject[$objectPath] = $fieldOptions;
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        if (function_exists('set_transient')) {
            set_transient('meros_crm_salesforce_schema_options', [
                'objects' => $objectOptions,
                'fields_by_object' => $fieldOptionsByObject,
            ], 5 * MINUTE_IN_SECONDS);
        }

        return [$objectOptions, $fieldOptionsByObject];
    }
}
