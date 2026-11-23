<?php

namespace MM\Meros\Crm\App\Components;

use Illuminate\Support\Str;

use MM\Meros\Contracts\Features\Automation\FlowAction;
use MM\Meros\Contracts\Features\Automation\FlowActionResponse;

use MM\Meros\Contracts\Orchestrators\ComponentsOrchestrator;

use MM\Meros\Crm\App\Components\Fields\SalesforceLookup;
use MM\Meros\Crm\App\Components\FormActions\CreateContact;

use MM\Meros\Crm\Facades\Salesforce;

class Orchestrator extends ComponentsOrchestrator {
    protected function configure(): void {
        $this->fields()->register(SalesforceLookup::class, 'lookup_salesforce');
        $this->formActions()->register(CreateContact::class, 'create_contact');

        if (Salesforce::hasActiveConnection()) {
            $this->configureSalesforceFlowActions();
        }
    }

    /**
     * Configures Salesforce flow actions for each invokable Salesforce flow.
     *
     * @return void
     */
    private function configureSalesforceFlowActions(): void {
        $invokableFlows = Salesforce::getInvokableFlows();

        if ($invokableFlows === []) {
            return;
        }

        foreach ($invokableFlows as $flow) {
            $flowName = $flow['sf_flow_name'] ?? null;

            if ($flowName === null || empty($flowName)) {
                continue;
            }

            $flowInputs = $flow['sf_flow_inputs_cache'] ?? [];
            $flowOutputs = $flow['sf_flow_outputs_cache'] ?? [];

            $this->formActions()->make(function (FlowAction $action) use ($flowName, $flowInputs, $flowOutputs) {
                $flowLabel = Str::title(Str::replace('_', ' ', $flowName));
                $action->name($flowLabel);
                $action->label('Invoke Salesforce Flow: ' . $flowLabel);
                $action->runner(function (array $inputs) use ($flowName): FlowActionResponse {
                    $awaitResponse = $inputs['await_response'] ?? true;
                    if (!is_bool($awaitResponse)) {
                        return new FlowActionResponse(null, 'Await Response must be a boolean.');
                    }
                    unset($inputs['await_response']);

                    $result = Salesforce::invokeFlow($flowName, $inputs, $awaitResponse);
                    if (!is_array($result)) {
                        return new FlowActionResponse(null, is_string($result) ? $result : 'Salesforce flow is unavailable.');
                    }

                    return new FlowActionResponse($awaitResponse ? $result['outputValues'] : []);
                });

                foreach ($flowInputs as $input) {
                    $name = $input['name'] ?? null;
                    $type = $input['type'] ?? null;
                    $required = $input['required'] ?? false;
                    $label = $input['label'] ?? null;
                    $description = $input['description'] ?? null;

                    if ($name === null || $type === null) {
                        continue;
                    }

                    if ($name === 'await_response') {
                        throw new \InvalidArgumentException("Salesforce flow '{$flowName}' uses the reserved input name 'await_response'.");
                    }

                    $dataType = Salesforce::getFlowVariableType($input);
                    $action->addInput($name, $dataType, $dataType === 'integer|double' ? 'number' : null, $label, $description, $required);
                }

                $action->addInput('await_response', 'boolean', 'checkbox', 'Await Response', 'Wait for outputs before continuing. Otherwise, queue the invocation without outputs.', false);
                $schema = $action->getInputSchema();
                $schema['await_response']['allow_dynamic_value'] = false;
                $schema['await_response']['default'] = true;
                $action->setInputSchema($schema);

                $outputSchema = [];
                foreach ($flowOutputs as $output) {
                    $name = $output['name'] ?? null;
                    if (!is_string($name) || $name === '') {
                        throw new \InvalidArgumentException("Salesforce flow '{$flowName}' has an output without a name.");
                    }
                    $outputSchema[$name] = [
                        'type' => Salesforce::getFlowVariableType($output),
                    ];
                }
                $action->setOutputSchema($outputSchema);
            });
        }
    }
}