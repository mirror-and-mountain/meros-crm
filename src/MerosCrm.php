<?php

namespace MM\Meros\Crm;

use MM\Meros\App\Package;

use MM\Meros\Crm\FormActions\CreateSalesforceContact;
use MM\Meros\Crm\FormActions\RunCrmSyncJobs;
use MM\Meros\Crm\Integrations\Salesforce;
use MM\Meros\Crm\Support\Integrations\CRM\SyncJobRunner;
use MM\Meros\Crm\Support\Integrations\CRM\SyncValueResolver;

class MerosCrm extends Package {
    public string $name = 'Meros CRM';

    public string $author = 'Meros';

    public string $handle = 'meros_crm';

    public string $authorUri = 'https://mirrorandmountain.com';

    public string $authorSupportUri = 'https://mirrorandmountain.com';

    public string $description = 'Salesforce-specific CRM integrations for Meros.';

    protected function configure(): void {
        app()->singleton(SyncValueResolver::class, function () {
            return new SyncValueResolver();
        });

        app()->singleton(SyncJobRunner::class, function ($app) {
            return new SyncJobRunner(
                $app->make(SyncValueResolver::class)
            );
        });

        $this->integrations()->register('salesforce', Salesforce::class);
        $this->formActions()->register('create_salesforce_contact', CreateSalesforceContact::class);
        $this->formActions()->register('run_crm_sync_jobs', RunCrmSyncJobs::class);
    }
}
