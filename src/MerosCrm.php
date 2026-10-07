<?php

namespace MM\Meros\Crm;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

use MM\Meros\App\Package;
use MM\Meros\Crm\App\Integrations\Salesforce as SalesforceIntegration;

use MM\Meros\Contracts\Features\Admin\Setting;
use MM\Meros\Contracts\Features\Content\PostType;

use MM\Meros\Contracts\Features\Components\Form;
use MM\Meros\Contracts\Features\Components\Field;

use MM\Meros\App\Components\Fields\Lookup;
use MM\Meros\App\Components\Fields\Repeater;
use MM\Meros\App\Components\Fields\Select;

use MM\Meros\Crm\Facades\Salesforce;
use MM\Meros\Facades\Content\PostTypes;

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
        // dd(Salesforce::participants()->get());

        $this->initSyncablePostTypes();
    }

    private function initSyncablePostTypes(): void {
        add_action('meros_framework_booted', function () {
            $postTypes = PostTypes::all()
                ->where('syncable', true);

            if ($postTypes->isEmpty()) {
                return;
            }

            $salesforceSyncables = [];

            foreach ($postTypes as $postType) {
                if (!($postType instanceof PostType) ||
                    !method_exists($postType, 'getSyncServices')) {
                    continue;
                }

                $services = $postType->getSyncServices();
                
                if ($services === []) {
                    continue;
                }

                foreach ($services as $service) {
                    if (in_array($service, ['salesforce']) ) {
                        $salesforceSyncables[] = $postType;
                    }
                }
            }

            if ($salesforceSyncables !== []) {
                $settings = Salesforce::getInstance()->getSettingsContainer();
                $repeaterValue = [];

                foreach ($salesforceSyncables as $syncable) {
                    $row = [
                        'cpt' => $syncable->getSingularLabel()
                    ];

                    $repeaterValue[] = $row;
                }

            
                $settings->add('object', function (Setting $setting) use ($repeaterValue) {
                    $setting->name('sf_syncable_cpts');
                    $setting->label('Syncable Post Types');
                    $setting->default($repeaterValue);
                    $setting->field('repeater', function (Repeater $repeater) {
                        $repeater->allowAdd(false);
                        $repeater->allowReorder(false);
                        $repeater->allowRemove(false);
                        $repeater->editRowText('Configure');

                        $repeater->field('text', function (Field $field) {
                            $field->name('cpt');
                            $field->label('Post Type');
                            $field->readonly();
                        });

                        $repeater->field('select', function (Select $field) {
                            $field->name('sf_object');
                            $field->label('Salesforce Object');
                            $field->default('');

                            $sfObjects = Salesforce::queryableObjects()->get();
                            $options = ['Select' => 'Select...'];

                            if (!($sfObjects instanceof Collection)) {
                                return;
                            }

                            $sfObjects = $sfObjects->where('name', '!==', 'Object');

                            if (!$sfObjects->isEmpty()) {
                                foreach ($sfObjects as $object) {
                                    if (!is_array($object) || 
                                        !array_key_exists('name', $object) ||
                                        !array_key_exists('label', $object)
                                    ) {
                                        continue;
                                    }

                                    $options[$object['name']] = $object['label'];
                                }
                            }
                            
                            $field->options($options);
                        });

                        $repeater->editForm(function (Form $form, array $rowData, array $formData) {
                            $form->field('select', function (Select $field) {
                                $field->name('sf_syncable_cpts_sync_type');
                                $field->label('Type');
                                $field->options([
                                    ''              => 'Disabled',
                                    'sf_to_wp'      => 'Salesforce to Wordpress Only',
                                    'wp_to_sf'      => 'Wordpress to Salesforce Only',
                                    'bidirectional' => 'Bidirectional'
                                ]);
                            });

                            $form->field('repeater', function (Repeater $repeater) use ($rowData) {
                                $repeater->name('sf_syncable_cpts_field_map');
                                $repeater->label('Field Mappings');
                                $repeater->allowReorder(false);

                                $cpt = $rowData['cpt'] ?? null;
                                $sfObject = $rowData['sf_object'] ?? null;

                                if ($cpt === null || $sfObject === null) {
                                    return;
                                }

                                $cptObject = PostTypes::all()->firstWhere(function (PostType $postType) use ($cpt) {
                                    return $postType->getSingularLabel() === $cpt;
                                });

                                if (!($cptObject instanceof PostType)) {
                                    return;
                                }

                                $sfObjectFields = Salesforce::objectFields($sfObject)->get();

                                if (!($sfObjectFields instanceof Collection)) {
                                    return;
                                }

                                $sfObjectFields = $sfObjectFields->sortBy('name')->toArray();

                                if (!is_array($sfObjectFields)) {
                                    return;
                                }

                                $repeater->field('select', function (Select $field) use ($cptObject) {
                                    $field->name('cpt_field');
                                    $field->label('Local Field');
                                    
                                    $schema = $cptObject->getSchema();

                                    if (!is_array($schema) || !array_key_exists('properties', $schema)) {
                                        return;
                                    }

                                    $properties = $schema['properties'];
                                    $options = ['' => 'Select...'];

                                    foreach ($properties as $prop => $data) {
                                        $options[$prop] = Str::replace(['-', '_'], ' ', Str::title($prop));
                                    }

                                    $field->options($options);
                                });

                                $repeater->field('select', function (Select $field) use ($sfObjectFields) {
                                    $field->name('sf_field');
                                    $field->label('Salesforce Field');

                                    $options = ['' => 'Select...'];

                                    foreach ($sfObjectFields as $sfField) {
                                        $label = $sfField['label'] ?? null;
                                        $value = $sfField['name'] ?? null;

                                        if ($label === null || $value === null) {
                                            continue;
                                        }

                                        $options[$value] = $label;
                                    } 

                                    $field->options($options);
                                });
                            });

                            return $form;
                        });
                    });
                });
            }
        });
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
