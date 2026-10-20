<?php

namespace MM\Meros\Crm\App\Components\FormActions;

use MM\Meros\Contracts\Features\Automation\FlowAction;
use MM\Meros\Contracts\Features\Automation\FlowActionResponse;

use MM\Meros\Crm\App\Models\Contact;

final class CreateContact extends FlowAction {
    protected array $inputSchema = [
        'contact_first_name' => [
            'label'      => "The contact's first name",
            'type'       => 'string',
            'required'   => true
        ],
        'contact_last_name' => [
            'label'    => "The contact's last name",
            'type'     => 'string',
            'required' => true
        ],
        'contact_email' => [
            'label'    => "The contact's email address",
            'type'     => 'string',
            'required' => true
        ]
    ];

    protected array $outputSchema = [
        'contact_id' => [
            'type' => 'string'
        ], 
        'contact_first_name' => [
            'type' => 'string'
        ],
        'contact_last_name' => [
            'type' => 'string'
        ],
        'contact_email' => [
            'type' => 'string'
        ]
    ];

    protected function configure(): void {
        $this->name('create_contact');
        $this->label('Create Contact');
    }

    public function run(array $input): FlowActionResponse {
        $result = Contact::updateOrCreate(
            ['primary_email' => $input['contact_email'] ?? null],
            [
                'first_name'    => $input['contact_first_name'] ?? null,
                'last_name'     => $input['contact_last_name'] ?? null,
                'primary_email' => $input['contact_email'] ?? null
            ]
        );

        if ($result !== null) {
            return new FlowActionResponse([
                'contact_id'         => $result->id,
                'contact_first_name' => $result->first_name,
                'contact_last_name'  => $result->last_name,
                'contact_email'      => $result->primary_email
            ]);
        }

        return new FlowActionResponse(null, 'Failed to update or create contact');
    }
}