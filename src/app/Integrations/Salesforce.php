<?php 

namespace MM\Meros\Crm\App\Integrations;

use Illuminate\Support\Str;

use MM\Meros\Contracts\Features\Integrations\OAuthIntegration;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesBaseUrl;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesClientId;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesClientSecret;

use MM\Meros\App\Components\Fields\Repeater;

class Salesforce extends OAuthIntegration {
    /**
     * The Salesforce API version currently being used by the integration.
     *
     * @var string
     */
    protected string $apiVersion = 'v67.0';
    
    /**
     * The current query to be executed via the get() method.
     *
     * @var array
     */
    private array $currentQuery = [
        'object'   => null,
        'recordId' => null,
        'wheres'   => [],
        'limit'    => 200
    ];

    /**
     * Stored queryable objects from the integration's queryable_objects setting;
     *
     * @var array|null
     */
    private array|null $queryableObjects = null;

    use UsesBaseUrl, UsesClientId, UsesClientSecret;

    // ===================================================================================
    // Initialisation
    // ===================================================================================

    public function getInstance(): self {
        return $this;
    }

    protected function environments(): array {
        return [
            'sandbox'    => 'Sandbox',
            'production' => 'Production'
        ];
    }

    protected function configure(): void {
        $this->label('Meros Salesforce');
        $this->description('Integration with Salesforce CRM for syncing data and managing customer relationships.');

        // Configure the base uri setting
        $this->baseUrlLabel = 'Salesforce Instance URL';
        $this->baseUrlDescription = 'The base URL of your Salesforce instance.';
        $this->baseUrlPlaceholder = 'https://your-instance.my.salesforce.com';

        // Configure the client id setting
        $this->clientIdLabel = 'Salesforce Client ID';
        $this->clientIdDescription = 'The client ID of your Salesforce connected app.';

        // Configure the client secret setting
        $this->clientSecretLabel = 'Salesforce Client Secret';
        $this->clientSecretDescription = 'The client secret of your Salesforce connected app.';
    }

    protected function initCustomSettings(): void {
        $this->settings()->add('object', function ($setting) {
            $setting->name('sf_queryable_objects');
            $setting->label('Queryable Objects');
            $setting->field('repeater', function (Repeater $repeater) {
                $repeater->label('Queryable Objects');
                $repeater->allowReorder(false);
                $repeater->field('text', [])
                    ->name('sf_object_name')
                    ->description('The API name of the object exactly as it is shown in Salesforce.')
                    ->label('Object Name');
                $repeater->field('text', [])
                    ->name('sf_object_nickname')
                    ->description('An optional nickname for the field which can be used in-place of the name.')
                    ->label('Object Nickname');
                $repeater->field('text', [])
                    ->name('sf_object_fields')
                    ->label('Object Fields')
                    ->description('A comma-separated list of fields to return when the object is queried. Setting "All" will return all fields on the object.')
                    ->default('All');
            });
        });
    }

    // ===================================================================================
    // Authorization Flows
    // ===================================================================================

    protected function getAuthorizationUrl(): string {
        return '{base_url}/services/oauth2/authorize';
    }

    protected function getTokenRequestEndpoint(): string {
        return '{base_url}/services/oauth2/token';
    }

    // ===================================================================================
    // Query Building
    // ===================================================================================

    /**
     * Magic method to try and resolve a query call based on whether the given method corresponds to
     * a queryable object.
     *
     * @param string $method
     * @param array  $args
     *
     * @return self
     */
    public function __call(string $method, array $args = []): self {
        $possibleObject = ucfirst(Str::singular($method));

        if (!$this->hasQueryableObject($possibleObject)) {
            throw new \BadFunctionCallException('Method "' . $method . '" does not exist on ' . static::class . '.');
        }

        $this->currentQuery['object'] = $possibleObject;

        if (!empty($args) && is_string($args[0])) {
            $this->currentQuery['recordId'] = $args[0];
        }

        return $this;
    }

    /**
     * Adds an object name and recordId to the currentQuery.
     *
     * @param string $object
     * @param string $recordId
     *
     * @return self
     */
    public function record(string $object, string $recordId): self {
        $this->currentQuery['object'] = $object;
        $this->currentQuery['recordId'] = $recordId;
        return $this;
    }

    /**
     * Adds an object name to the currentQuery.
     *
     * @param string $object
     *
     * @return self
     */
    public function records(string $object): self {
        $this->currentQuery['object'] = $object;
        return $this;
    }

    /**
     * Adds a where argument to the currentQuery.
     *
     * @param string      $field
     * @param mixed       $operator
     * @param string|null $value
     *
     * @return self
     */
    public function where(string $field, mixed $operator, ?string $value = null): self {
        $args = func_get_args();

        if (count($args) === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->currentQuery['wheres'][] = compact('field', 'operator', 'value');
        return $this;
    }

    /**
     * Sets the limit on the currentQuery.
     *
     * @param integer $limit
     *
     * @return self
     */
    public function limit(int $limit): self {
        $this->currentQuery['limit'] = $limit;
        return $this;
    }

    /**
     * Executes an HTTP request based on the currentQuery property, returning a single record if found, 
     * or an array of records if any are returned based on the configured query.
     * 
     * @param bool $collect Whether to return the retrieved records as a collection (applicable only when the current query is looking for multiple records).
     *
     * @return object|array|null
     */
    public function get(bool $collect = true): object|array|null {
        $possibleObject = $this->currentQuery['object'] ?? null;
        if ($possibleObject === null) {
            throw new \InvalidArgumentException('Object type must be specified before making a GET request.');
        }

        $queryableObject = $this->getQueryableObject($possibleObject);  
        if ($queryableObject === null) {
            throw new \InvalidArgumentException("The object of type {$possibleObject} is not set to be queryable.");
        }

        $objectName = $queryableObject['name'];
        $recordId   = $this->currentQuery['recordId'] ?? null;
        $fields     = $queryableObject['fields'];
        $response   = [];

        if ($recordId === null) {
            $response = $this->getWithSOQL($objectName, $fields);

            if (is_string($response)) {
                $this->logError($response);
                return null;
            }

            return $collect ? collect($response) : $response;
        }

        else {
            $response = $this->getSingleRecord($objectName, $recordId, $fields);
            if (is_string($response)) {
                $this->logError($response);
                return null;
            }
        }

        if (is_array($response)) {
            return $collect ? (object) $response : $response;
        }

        return null;
    }

    /**
     * Returns the first record found with the currentQuery, if any.
     *
     * @return object|array|null
     */
    public function first(bool $asObject = true): object|array|null {
        $possibleObject = $this->currentQuery['object'] ?? null;
        if ($possibleObject === null) {
            throw new \InvalidArgumentException('Object type must be specified before making a GET request.');
        }

        $queryableObject = $this->getQueryableObject($possibleObject);
        if ($queryableObject === null) {
            throw new \InvalidArgumentException("The object of type {$possibleObject} is not set to be queryable.");
        }

        $objectName = $queryableObject['name'];
        $fields     = $queryableObject['fields'];
        $response   = $this->getWithSOQL($objectName, $fields);

        if (is_string($response)) {
            $this->logError($response);
            return null;
        }

        $record = collect($response)->first();
        
        if (is_array($record)) {
            return $asObject ? (object) $record : $record;
        }

        return null;
    }

    /**
     * Retrieves a single record (if found) based on the currentQuery property.
     *
     * @return array|string
     */
    private function getSingleRecord(string $objectName, string $recordId, string $fields): array|string {
        $url = $this->buildRequestUrl("{base_url}/services/data/{$this->apiVersion}/sobjects/{$objectName}/{$recordId}");
        return $this->request($url, $fields === 'All' ? [] : ['fields' => $fields]);
    }

    /**
     * Makes an HTTP request using the generated SOQL query and returns the response.
     *
     * @return array
     */
    private function getWithSOQL(string $objectName, string $fields): array|string {
        $query = $this->buildSOQLQuery($objectName, $fields);
        $url   = $this->buildRequestUrl("{base_url}/services/data/{$this->apiVersion}/query");
        
        return $this->request($url, ['q' => $query], 'records');
    }

    /**
     * Builds an SOQL query based on the currentQuery property.
     *
     * @return string
     */
    private function buildSOQLQuery(string $objectName, string $fields): string {
        $limit = $this->currentQuery['limit'] ?? 200;

        if ($limit > 200) {
            $limit = 200;
        }

        $whereClauses = [];
        foreach ($this->currentQuery['wheres'] ?? [] as $where) {
            $field    = $where['field'];
            $operator = $where['operator'];
            $value    = is_string($where['value'])
                ? "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $where['value']) . "'"
                : $where['value'];
            $whereClauses[] = "{$field} {$operator} {$value}";
        }

        $whereString  = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        $selectFields = $fields === 'All' ? 'FIELDS(ALL)' : $fields;

        return "SELECT {$selectFields} FROM {$objectName}{$whereString} LIMIT {$limit}";
    }

    /**
     * Makes a 'GET' HTTP request to Saleforce using the provided URL and payload.
     *
     * @param string $url
     * @param array  $payload
     * @param string $returnKey
     *
     * @return array|string
     */
    private function request(string $url, array $payload, string $returnKey = ''): array|string {
        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return 'Cannot locate access token for request.';
        }

        $response = $this->httpClient()->send([
            'method'  => 'GET',
            'url'     => $url,
            'payload' => $payload,
            'headers' => [
                'Authorization' => "Bearer {$this->getAccessToken()}",
                'Accept'        => 'application/json',
            ],
        ]);

       $decodedResponse = json_decode($response->getBody(), true);
       $error = $this->checkForResponseError($decodedResponse);

       if ($error !== null) {
            return $error;
       }

       return !empty($returnKey) ? $decodedResponse[$returnKey] ?? [] : $decodedResponse;
    }

    // ===================================================================================
    // Helpers
    // ===================================================================================

    /**
     * Retrieves objects that are queryable via this integration. The returned array includes 
     * allowed objects as keys and their queryable fields as values.
     *
     * @return array
     */
    private function getQueryableObjects(bool $refresh = false): array {
        if (is_array($this->queryableObjects) && $refresh === false) {
            return $this->queryableObjects;
        }

        $currentEnv = $this->getCurrentEnvironment();
        $storedObjects = $this->settings->getItemValue('sf_queryable_objects_' . $currentEnv);
        $objects = [];
        
        foreach ($storedObjects as $value) {
            $object         = $value['sf_object_name'] ?? null;
            $objectNickname = $value['sf_object_nickname'] ?? '';
            $fields         = $value['sf_object_fields'] ?? '';

            if ($object !== null) {
                if ($fields === '') {
                    $fields = ['ID','Name'];
                } 
                
                else if ($fields !== 'All') {
                    $fields = explode(',', $fields);

                    foreach ($fields as $key => $field) {
                        $fields[$key] = ltrim($field);
                    }

                    if (!in_array('Id', $fields)) {
                        $fields[] = 'Id';
                    }

                    if (!in_array('Name', $fields)) {
                        $fields[] = 'Name';
                    }
                }

                $objects[$object] = [
                    'name'     => $object,
                    'nickname' => $objectNickname,
                    'fields'   => $fields === 'All' ? 'All' : implode(',', $fields)
                ];
            }
        }

        if ($objects === []) {
            return ['Contact' => ['Id','Name'], 'Account' => ['Id','Name']];
        }

        return $objects;
    }

    /**
     * Retrieves a single object from the queryable objects array, if it exists.
     *
     * @param string $objectName
     *
     * @return array|null
     */
    private function getQueryableObject(string $objectName): ?array {
        $objects = $this->getQueryableObjects();

        if (array_key_exists($objectName, $objects)) {
            return $objects[$objectName];
        }

        return $this->getQueryableObjectByNickname($objectName);
    }

    /**
     * Returns a single object from the queryable objects array using its nickname, if it exists.
     *
     * @param string $nickname
     *
     * @return array|null
     */
    private function getQueryableObjectByNickname(string $nickname): ?array {
        $objects = collect($this->getQueryableObjects());
        
        return $objects->firstWhere('nickname', $nickname);
    }

    /**
     * Checks whether the given object name is available in the queryable objects array.
     *
     * @param string $objectName
     *
     * @return boolean
     */
    private function hasQueryableObject(string $objectName): bool {
        $objects = $this->getQueryableObjects();

        if (array_key_exists($objectName, $objects)) {
            return true;
        }

        if ($this->getQueryableObjectByNickname($objectName) !== null) {
            return true;
        }

        return false;
    }

    /**
     * Checks the given response array for possible error messages and returns the first error message found, if any.
     *
     * @param array $responseItems
     *
     * @return string|null
     */
    private function checkForResponseError(array $responseItems): ?string {
        foreach ($responseItems as $value) {
            if (is_array($value)) {
                if (in_array('errorCode', array_keys($value))) {
                    return $value['errorCode'];
                }
            }
       }

       return null;
    }
}