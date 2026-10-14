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

    public static function getBuilderControls(): ?FieldGroup {
        $group = parent::getBuilderControls();

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
     
    protected function configure(): void {
        $this->clearFilters();
        $this->model(Salesforce::class);
        $this->key('Id');
        
        if (array_key_exists('filters', $this->passedProps) && is_array($this->passedProps['filters'])) {
            $this->filters($this->passedProps['filters']);
        }
    }
}