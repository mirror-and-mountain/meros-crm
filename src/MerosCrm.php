<?php

namespace MM\Meros\Crm;

use MM\Meros\App\Package;
use MM\Meros\Crm\App\Integrations\Salesforce as SalesforceIntegration;

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

    /**
     * Configures the package, registering any necessary settings, tables, and other features.
     *
     * @return void
     */
    public function configure(): void {
        $this->tables()->register();
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
