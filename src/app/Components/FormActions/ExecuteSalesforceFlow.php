<?php

namespace MM\Meros\Crm\App\Components\FormActions;

use MM\Meros\Contracts\Features\Automation\FlowAction;
use MM\Meros\Contracts\Features\Automation\FlowActionResponse;

use Illuminate\Support\Facades\Log;

class ExecuteSalesforceFlow extends FlowAction {
    protected array $outputSchema = [
        'flow_response' => [
            'type' => 'integer'
        ]
    ];

    public function run(array $input): FlowActionResponse {
        Log::info('input', $input);
        return new FlowActionResponse($this, true);
    }
}