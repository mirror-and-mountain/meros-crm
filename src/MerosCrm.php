<?php

namespace MM\Meros\Crm;

use MM\Meros\App\Package;

use MM\Meros\Crm\App\Components\Orchestrator as ComponentsOrchestrator;
use MM\Meros\Crm\App\Integrations\Salesforce as SalesforceIntegration;

use MM\Meros\Contracts\Features\Admin\Setting;

final class MerosCrm extends Package {
    /**
     * Initialises the package.
     *
     * @return void
     */
    protected function init(): void {
        $this->setAuthor('Meros');
        $this->setAuthorUrl('https://meroscrm.com');
        $this->setSupportUrl('https://meroscrm.com/support');
        $this->setDescription('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua');
    }

    public function configure(): void {
        $this->tables()->register();

        $this->configureSettings();
        $this->configureIntegrations();
        $this->initialise(ComponentsOrchestrator::class);
    }

    /**
     * Configures the settings for the package.
     *
     * @return void
     */
    private function configureSettings(): void {
        $this->settings()->add('boolean', function (Setting $setting) {
            $setting->name('meros_crm_use_portal');
            $setting->label('Use Customer Portals');
            $setting->description('Customer portals are a ...');
            $setting->default(false);
            $setting->field();
        });
    }

    /**
     * Configures the integrations for the package.
     *
     * @return void
     */
    private function configureIntegrations(): void {
        $this->integrations(SalesforceIntegration::class)->make();
    }

    /**
     * Overrides default to return captialised 'CRM'.
     *
     * @return string
     */
    public function getName(): string {
        return 'Meros CRM';
    }
}
