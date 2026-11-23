<?php

namespace MM\Meros\Crm\App\Components\Fields;

use MM\Meros\App\Components\Fields\Lookup;
use MM\Meros\Contracts\Features\Components\FieldGroup;

use MM\Meros\App\Components\Fields\Repeater;

use MM\Meros\Crm\Facades\Salesforce;

class SalesforceLookup extends Lookup {
    protected string $object = 'contact';

    /**
     * Override the Lookup contract's form builder setting.
     *
     * @var boolean
     */
    protected static bool $showInFormBuilder = true;
     
    protected function configure(): void {
        $this->clearFilters();
        $this->model(Salesforce::class);
        $this->key('Id');
        
        if (array_key_exists('filters', $this->passedProps) && is_array($this->passedProps['filters'])) {
            $this->filters($this->passedProps['filters']);
        }
    }

    /**
     * Adds builder controls specific to the Salesforce lookup field.
     *
     * @param FieldGroup $group
     * @param boolean    $locked
     * @param array      $rowData The data for the current row in the form builder.
     *
     * @return FieldGroup
     */
    protected static function addBuilderControls(FieldGroup $group,bool $locked = false, array $rowData = []): FieldGroup {
        $group->row(function ($row) {
            $row->field('select', [
                'name'    => 'object',
                'label'   => 'Salesforce Object',
                'options' => Salesforce::getQueryableObjectsList()
            ]);

            $row->field('text', [
                'name'    => 'labelledBy',
                'label'   => 'Search Field',
                'default' => 'Name'
            ]);
        });

        $group->field('repeater', function (Repeater $repeater) {
            $repeater->name('filters');
            $repeater->label('Filters');

            $repeater->field('text', [
                'name'  => 'field',
                'label' => 'Field'
            ]);

            $repeater->field('select', [
                'name'    => 'operator',
                'label'   => 'Operator',
                'options' => [
                    '='   => 'Equals',
                    '!='  => 'Not Equals',
                ],
                'default' => '='
            ]);

            $repeater->field('text', [
                'name'  => 'value',
                'label' => 'Value'
            ]);
        });

        $group->field('text', [
            'name'  => 'defaultValue',
            'label' => 'Default Value'
        ]);

        return $group;
    }
}