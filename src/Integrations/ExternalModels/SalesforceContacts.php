<?php

namespace MM\Meros\Crm\Integrations\ExternalModels;

use Illuminate\Http\Client\Response;
use MM\Meros\Support\ExternalModel;

/**
 * Class SalesforceContacts
 *
 * This class provides an interface to interact with Salesforce Contacts via the Salesforce API.
 * It extends the ExternalModel class, which provides methods for making HTTP requests to external services.
 *
 * @package MM\Meros\Crm\Integrations\ExternalModels
 */
final class SalesforceContacts extends ExternalModel {
    public function __construct() {
        parent::__construct();

        $this->integration('salesforce')
            ->usingEnvironment('production')
            ->path('sobjects/Contact');
    }

    /**
     * Sets the integration connection to be used for subsequent requests.
     *
     * @param string $label The label of the integration connection to use.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function usingConnection(string $label): static {
        $this->using($label);

        return $this;
    }

    /********************************************************************
     * 
     * Stock Salesforce Operations e.g. for 'Contacts,' 'Accounts,' etc.
     * 
     ********************************************************************/

    /**
     * Creates a new Salesforce contact with the provided payload.
     *
     * @param array $payload The data for the new contact.
     *
     * @return array The response from the Salesforce API.
     *
     * @throws \RuntimeException If the request fails.
     */
    public function createContact(array $payload): array {
        $response = $this->asJson()
            ->payload($payload)
            ->post();

        $this->throwIfFailed($response, 'create Salesforce contact');

        return $response->json() ?? [];
    }

    /**
     * Finds a Salesforce contact by email address.
     *
     * @param string $email The email address of the contact to find.
     *
     * @return array The response from the Salesforce API.
     *
     * @throws \RuntimeException If the request fails.
     */
    public function findByEmail(string $email): array {
        $soql = sprintf(
            "SELECT Id, FirstName, LastName, Email FROM Contact WHERE Email = '%s' LIMIT 1",
            str_replace("'", "\\'", $email)
        );

        $response = $this->asJson()
            ->path('query')
            ->query(['q' => $soql])
            ->get();

        $this->throwIfFailed($response, 'query Salesforce contacts');

        return $response->json() ?? [];
    }

    /***************************************************************
     * 
     * Error handling
     * 
     ***************************************************************/

    /**
     * Throws a RuntimeException if the HTTP response indicates a failure.
     *
     * @param Response $response
     * @param string   $action
     *
     * @return void
     */
    private function throwIfFailed(Response $response, string $action): void {
        if ($response->failed()) {
            throw new \RuntimeException('Failed to ' . $action . ': ' . $response->body());
        }
    }
}
