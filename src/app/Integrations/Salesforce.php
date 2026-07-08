<?php

namespace MM\Meros\Crm\App\Integrations;

use MM\Meros\Services\Contracts\Integration as IntegrationDefinition;

final class Salesforce extends IntegrationDefinition {
    public string $handle = 'salesforce';

    protected string $label = 'Salesforce';

    protected string $description = 'Connect Salesforce CRM data to Meros integrations and sync workflows.';

    protected string $category = 'crm';

    protected string $authType = 'oauth';

    protected string $baseUri = 'https://login.salesforce.com/services/data';

    protected string $apiVersion = 'v60.0';

    protected array $scopes = [
        'api',
        'refresh_token',
        'offline_access',
    ];

    public function __construct(\MM\Meros\Services\Contracts\FeatureProvider $provider, array $props = []) {
        parent::__construct($provider, $props);

        $this->configuration(function ($fields) {
            $fields->select('default_environment')
                ->label('Default OAuth Environment')
                ->options([
                    'production' => 'Production',
                    'sandbox'    => 'Sandbox',
                    'test'       => 'Test',
                ])
                ->default('production');

            $fields->text('org_domain_production')
                ->label('Salesforce Org Domain (Production)')
                ->helpText('Host only, for example company.my.salesforce.com');
            $fields->text('client_id_production')->label('Client ID (Production)');
            $fields->password('client_secret_production')->label('Client Secret (Production)');

            $fields->text('org_domain_sandbox')
                ->label('Salesforce Org Domain (Sandbox)')
                ->helpText('Host only, for example company--sandbox.sandbox.my.salesforce.com');
            $fields->text('client_id_sandbox')->label('Client ID (Sandbox)');
            $fields->password('client_secret_sandbox')->label('Client Secret (Sandbox)');

            $fields->text('org_domain_test')
                ->label('Salesforce Org Domain (Test)')
                ->helpText('Host only, for example your-test-domain.my.salesforce.com');
            $fields->text('client_id_test')->label('Client ID (Test)');
            $fields->password('client_secret_test')->label('Client Secret (Test)');

            $fields->textarea('scopes')
                ->label('Scopes')
                ->default('api,refresh_token,offline_access')
                ->helpText('Comma-separated OAuth scopes to request or refresh.');
        });
    }
}
