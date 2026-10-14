<?php

namespace MM\Meros\Crm\App\Components\FormActions;

use MM\Meros\Contracts\Features\Components\FormAction;
use MM\Meros\Contracts\Features\Components\FieldGroup;

class ExecuteSalesforceFlow extends FormAction {
    protected function configure(): void {
        $this->name('execute_salesforce_action');
        $this->label('Execute Salesforce Action');
    }

    public static function getFormBuilderControls(
        FieldGroup $controlGroup, 
        array $rowData,
        array $repeaterData,
        array $formFields
    ): FieldGroup {    
        $controlGroup->field('text', [
            'name'     => 'sf_flow_api_name',
            'label'    => 'Flow API Name',
            'required' => true
        ]);

        $controlGroup->field('select', [
            'name'    => 'field_test',
            'label'   => 'Field Test',
            'options' => $formFields
        ]);

        return $controlGroup;
    }

    public function handle(int $formId, array $configuration, array $submittedData): bool {
        return true;
    }
}