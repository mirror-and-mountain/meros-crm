<?php 

namespace MM\Meros\Crm\App\Integrations;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;

use MM\Meros\Contracts\Features\Integrations\OAuthIntegration;
use MM\Meros\Contracts\Features\Integrations\ResolvesLookupOptions;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesBaseUrl;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesClientId;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesClientSecret;

use MM\Meros\Contracts\Features\Admin\Setting;

use MM\Meros\App\Components\Fields\Repeater;
use MM\Meros\App\Components\Fields\Checkbox;

use MM\Meros\Crm\App\Jobs\InvokeSalesforceFlow;

class Salesforce extends OAuthIntegration implements ResolvesLookupOptions {
    /**
     * The Salesforce API version currently being used by the integration.
     *
     * @var string
     */
    protected string $apiVersion = 'v67.0';

    /**
     * The category of the integration.
     *
     * @var string
     */
    protected string $category = 'crm';
    
    /**
     * The current query to be executed via get(), create(), or update().
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

    public function getInstance(): static {
        return $this;
    }

    protected function environments(): array {
        return [
            'sandbox'    => 'Sandbox',
            'production' => 'Production'
        ];
    }

    protected function configure(): void {
        $this->usePKCE();
        $this->useRefreshHeartbeat();

        $this->label('Salesforce');
        $this->description('Integration with Salesforce CRM for syncing data and managing customer relationships.');

        // Configure the base uri setting
        $this->baseUrlLabel = 'Salesforce Instance URL';
        $this->baseUrlDescription = 'The base URL of your Salesforce instance.';
        $this->baseUrlPlaceholder = 'https://your-instance.my.salesforce.com';

        // Configure the client id setting
        $this->clientIdLabel = 'Salesforce Client ID';
        $this->clientIdDescription = 'The Client ID provided by your Salesforce External Client App.';

        // Configure the client secret setting
        $this->clientSecretLabel = 'Salesforce Client Secret';
        $this->clientSecretDescription = 'The Client Secret provided by your Salesforce External Client App.';
    }

    protected function initCustomSettings(): void {
        /**
         * Adds a setting for configuring queryable Salesforce objects.
         */
        $this->settings()->add('object', function (Setting $setting) {
            $setting->name('sf_queryable_objects');
            $setting->label('Queryable Objects');
            $setting->description('List the Salesforce objects available for reading, creating, and updating via this integration.');

            $setting->onUpdate(function ($value, $oldValue, $itemName, $optionName) {
                foreach($value as $key => $object) {
                    if (!is_array($object) || !array_key_exists('sf_object_name', $object)) {
                        continue;
                    }

                    $name = $object['sf_object_name'];
                    $nickName = $object['sf_object_nickname'] ?? '';

                    if (!empty($nickName)) {
                        $value[$key]['sf_object_nickname'] = Str::snake(Str::replace('-', '_', $nickName));
                    }

                    $fieldsCache = is_array($oldValue) && array_key_exists($key, $oldValue) && array_key_exists('sf_fields_cache', $oldValue[$key])
                        ? $oldValue[$key]['sf_fields_cache']
                        : [];

                    $updateFieldsCache = array_key_exists('sf_object_update_fields', $object)
                        ? (bool) $object['sf_object_update_fields'] || $fieldsCache === []
                        : $fieldsCache === [];

                    $value[$key]['sf_object_update_fields'] = false;

                    if (!$updateFieldsCache) {
                        $value[$key]['sf_fields_cache'] = $fieldsCache;
                        continue;
                    }

                    $sfObject = $this->getSingleObject($name);
                    
                    if (!is_array($sfObject) || !array_key_exists('fields', $sfObject)) {
                        continue;
                    }

                    $fields = $sfObject['fields'];
                    
                    if (!is_array($fields)) {
                        continue;
                    }

                    $fields = array_map(function ($field) {
                        return array_intersect_key($field, array_flip([
                            'name', 
                            'label', 
                            'type', 
                            'nillable',
                            'picklistValues',
                            'updateable'
                        ]));
                    }, $fields);

                    $value[$key]['sf_fields_cache'] = $fields;
                }

                return $value;
                
            });

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
                    ->description('A comma-separated list of fields allowed in queries and record writes. Setting "All" allows all fields on the object.')
                    ->default('All');

                $repeater->field('checkbox', function (Checkbox $checkbox) {
                    $checkbox->name('sf_object_update_fields');
                    $checkbox->label('Update Fields Cache');
                    $checkbox->toggle(true);
                    $checkbox->default(false);
                });
            });
        });

        /**
         * Adds a setting for configuring runnable Salesforce flows.
         */
        $this->settings()->add('object', function (Setting $setting) {
            $setting->name('sf_invokable_flows');
            $setting->label('Automated Flows');
            $setting->description('List the automated flows you want to be runnable via this integration.');

            $setting->onUpdate(function ($value, $oldValue, $itemName, $optionName) {
                $getFlowData = function (string $type, int $flowKey, string $flowName, string $updateKey, string $cacheKey) use($value, $oldValue): array {
                    $cache = is_array($oldValue) && array_key_exists($flowKey, $oldValue) && array_key_exists($cacheKey, $oldValue[$flowKey])
                        ? $oldValue[$flowKey][$cacheKey]
                        : [];

                    $update = array_key_exists($updateKey, $value[$flowKey])
                        ? (bool) $value[$flowKey][$updateKey] || $cache === []
                        : $cache === [];

                    if (!$update) {
                        return $cache;
                    }

                    $flow = $this->flow($flowName)->get(false);

                    if (!is_array($flow) || !array_key_exists($type, $flow) || !is_array($flow[$type])) {
                        return [];
                    }

                    return $flow[$type];
                };

                foreach ($value as $key => $flowConfig) {
                    if (!is_array($flowConfig) || !array_key_exists('sf_flow_name', $flowConfig)) {
                        continue;
                    }

                    $flowName = $flowConfig['sf_flow_name'];

                    $inputsCache  = $getFlowData('inputs', $key, $flowName, 'sf_flow_update_cache', 'sf_flow_inputs_cache');
                    $outputsCache = $getFlowData('outputs', $key, $flowName, 'sf_flow_update_cache', 'sf_flow_outputs_cache');

                    $value[$key]['sf_flow_inputs_cache'] = $inputsCache;
                    $value[$key]['sf_flow_outputs_cache'] = $outputsCache;
                    $value[$key]['sf_flow_update_cache'] = false;
                }

                return $value;
            });

            $setting->field('repeater', function (Repeater $repeater) {
                $repeater->label('Automated Flows');
                $repeater->allowReorder(false);

                $repeater->field('text', [])
                    ->name('sf_flow_name')
                    ->description('The API name of the flow exactly as it is shown in Salesforce.')
                    ->label('Flow API Name');

                $repeater->field('checkbox', function (Checkbox $checkbox) {
                    $checkbox->name('sf_flow_update_cache');
                    $checkbox->label('Update Flow I/O Cache');
                    $checkbox->toggle(true);
                    $checkbox->default(false);
                });
            });
        });
    }

    /**
     * Returns an array of invokable Salesforce flows.
     *
     * @param boolean $refresh
     *
     * @return array
     */
    public function getInvokableFlows(bool $refresh = false): array {
        $settingValue = $this->settings('sf_invokable_flows', $refresh);
        return is_array($settingValue) ? $settingValue : [];
    }

    /**
     * Retrieves the configuration of a specific invokable Salesforce flow by its API name.
     *
     * @param string  $flowName
     * @param boolean $refresh
     *
     * @return array|null
     */
    public function getInvokableFlow(string $flowName, bool $refresh = false): ?array {
        $invokableFlows = collect($this->getInvokableFlows($refresh));
        return $invokableFlows->first(fn($flow) => ($flow['sf_flow_name'] ?? null) === $flowName) ?: null;
    }

    /**
     * Checks if a specific Salesforce flow is invokable.
     *
     * @param string  $flowName
     * @param boolean $refresh
     *
     * @return boolean
     */
    public function hasInvokableFlow(string $flowName, bool $refresh = false): bool {
        $invokableFlows = collect($this->getInvokableFlows($refresh));
        return $invokableFlows->contains(fn($flow) => ($flow['sf_flow_name'] ?? null) === $flowName);
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
    // Token Refresh Flow
    // ===================================================================================
    
    protected function shouldRefreshToken(string $errorTitle, string $errorMessage = '', ?int $errorCode = null): bool {
        if ($errorTitle === 'INVALID_SESSION_ID') {
            return true;
        }

        return false;
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
     * @return static
     */
    public function __call(string $method, array $args = []): static {
        $this->resetQuery();
        $possibleObject = ucfirst(Str::singular($method));

        if (!$this->hasQueryableObject($possibleObject)) {
            $this->logError('Method not found', 'Method "' . $method . '" does not exist on ' . static::class . '.');
            return $this;
        }

        $this->currentQuery['object'] = $possibleObject;

        if (!empty($args) && is_string($args[0])) {
            $this->currentQuery['recordId'] = $args[0];
        }

        return $this;
    }

    /**
     * Internal helper to reset the current query.
     *
     * @return void
     */
    private function resetQuery(): void {
        $this->currentQuery = [
            'object'   => null,
            'recordId' => null,
            'wheres'   => [],
            'limit'    => 200
        ];
    }

    /**
     * Sets the current query to retrieve a list of Salesforce objects.
     *
     * @return static
     */
    public function objects(): static {
        $this->resetQuery();
        $this->currentQuery['object'] = 'Object';
        return $this;
    }

    /**
     * Sets the current query to retrieve a list of queryable objects.
     *
     * @return static
     */
    public function queryableObjects(): static {
        $this->resetQuery();
        $this->currentQuery['object'] = 'QueryableObject';
        return $this;
    }

    /**
     * Sets the current query to retrieve a single object by its name.
     *
     * @param string $name
     *
     * @return static
     */
    public function object(string $name): static {
        $this->resetQuery();
        $name = ucfirst(Str::singular($name));
        $this->currentQuery['object'] = 'Object';
        $this->currentQuery['recordId'] = $name;
        return $this;
    }

    /**
     * Sets the current query to return a single queryable object, if it exists.
     *
     * @param string $objectName
     *
     * @return static
     */
    public function queryableObject(string $objectName): static {
        $this->resetQuery();
        $this->currentQuery['object'] = 'QueryableObject';
        $this->currentQuery['recordId'] = $objectName;
        return $this;
    }

    /**
     * Returns a list of stored fields for the given queryable object.
     *
     * @param string $objectName
     *
     * @return static
     */
    public function objectFields(string $objectName): static {
        $this->resetQuery();
        $this->currentQuery['object'] = 'ObjectFields';
        $this->currentQuery['recordId'] = $objectName;
        return $this;
    }

    /**
     * Adds an object name and recordId to the currentQuery.
     *
     * @param string $object
     * @param string $recordId
     *
     * @return static
     */
    public function record(string $object, string $recordId): static {
        $this->resetQuery();
        $this->currentQuery['object'] = ucfirst(Str::singular($object));
        $this->currentQuery['recordId'] = $recordId;
        return $this;
    }

    /**
     * Adds an object name to the currentQuery.
     *
     * @param string $object
     *
     * @return static
     */
    public function records(string $object): static {
        $this->resetQuery();
        $this->currentQuery['object'] = ucfirst(Str::singular($object));
        return $this;
    }

    /**
     * Sets the current query to find or execute an auto-launched flow, if one is found
     * using the given api name.
     *
     * @param string $apiName
     *
     * @return static
     */
    public function flow(string $apiName): static {
        $this->resetQuery();
        $this->currentQuery['object'] = 'Flow';
        $this->currentQuery['recordId'] = $apiName;
        return $this;
    }

    /**
     * Returns a single record using the given object and record Id, if one exists.
     *
     * @param string $object
     * @param string $recordId
     *
     * @return object|null
     */
    public function find(string $object, string $recordId): object|null {
        if ($object === 'Object') {
            return $this->object($recordId)->get();
        }

        if ($object === 'QueryableObject' || $object === 'queryableObjects') {
            $queryable = $this->getQueryableObject($recordId, true);
            if (is_array($queryable)) {
                return (object) $queryable;
            } else {
                return (object) [];
            }
        }

        return $this->record($object, $recordId)->get();
    }

    /**
     * Adds a where argument to the currentQuery.
     *
     * @param string  $field
     * @param mixed   $operator
     * @param mixed   $value
     *
     * @return static
     */
    public function where(string $field, mixed $operator, mixed $value = null): static {
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
     * @return static
     */
    public function limit(int $limit): static {
        $this->currentQuery['limit'] = $limit;
        return $this;
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

    // ===================================================================================
    // Retrieving data
    // ===================================================================================

    /**
     * Executes an HTTP request based on the currentQuery property, returning a single record if found, 
     * or an array of records if any are returned based on the configured query.
     * 
     * @param bool|array $collect Whether to return the retrieved records as a collection (applicable only when the current query is looking for multiple records).
     * @param bool $checkEnabled Whether to check if the integration is enabled before executing the query. Defaults to true.
     *
     * @return object|array|null
     */
    public function get(bool|array $collect = true, bool $checkEnabled = true): object|array|null {
        if ($this->hasActiveConnection($checkEnabled) === false) {
            return null;
        }

        if (is_array($collect)) {
            $collect = true;
        }

        $possibleObject = $this->currentQuery['object'] ?? null;
        if ($possibleObject === null) {
            $this->logError('Object type not specified for GET request.');
            return null;
        }

        $recordId = $this->currentQuery['recordId'] ?? null;

        if ($possibleObject === 'QueryableObject' && $recordId === null) {
            $response = $this->getQueryableObjects(true);
            return $collect ? collect($response) : $response;
        } 
        
        else if ($possibleObject === 'QueryableObject' && is_string($recordId) && !empty($recordId)) {
            $response = $this->getQueryableObject($recordId, true);
            return $collect ? (object) $response : $response;
        }

        else if ($possibleObject === 'ObjectFields' && is_string($recordId) && !empty($recordId)) {
            $object = $this->getQueryableObject($recordId, true);
            if (is_array($object) && array_key_exists('fields_cache', $object) && is_array($object['fields_cache'])) {
                return $collect ? collect($object['fields_cache']) : $object['fields_cache'];
            } else {
                return null;
            }
        }

        else if ($possibleObject === 'Flow' && $recordId !== null) {
            $response = $this->getFlow($recordId);
            return $response === null ? null : ($collect ? (object) $response : $response);
        }

        else if ($possibleObject === 'Flow' && $recordId === null) {
            $this->logError('A record id is required to retrieve a flow.');
            return null;
        }

        $queryableObject = $this->getQueryableObject($possibleObject);
        if ($queryableObject === null) {
            $this->logError("The object of type {$possibleObject} is not set to be queryable.");
            return null;
        }

        $objectName = $queryableObject['name'];
        $fields     = $queryableObject['fields'];
        $response   = [];

        if ($recordId === null) {
            if ($objectName === 'Object') {
                $response = $this->getObjects();
            } else {
                $response = $this->getWithSOQL($objectName, $fields);
            }

            if (is_string($response)) {
                $this->logError($response);
                return null;
            }

            return $collect ? collect($response) : $response;
        }

        else {
            $response = $objectName === 'Object' 
                ? $this->getSingleObject($recordId)
                : $this->getSingleRecord($objectName, $recordId, $fields);

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
     * @param bool $asObject Whether to return the record as an object. Defaults to true.
     * @param bool $checkEnabled Whether to check if the integration is enabled before executing the query. Defaults to true.
     *
     * @return object|array|null
     */
    public function first(bool $asObject = true, bool $checkEnabled = true): object|array|null {
        if ($this->hasActiveConnection($checkEnabled) === false) {
            return null;
        }

        $possibleObject = $this->currentQuery['object'] ?? null;
        if ($possibleObject === null) {
            $this->logError('Object type must be specified before making a GET request.');
            return null;
        }

        $queryableObject = $this->getQueryableObject($possibleObject);
        if ($queryableObject === null) {
            $this->logError("The object of type {$possibleObject} is not set to be queryable.");
            return null;
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
     * Retrieves an Autolaunched flow from the org by its API name, if found.
     *
     * @param string $apiName
     *
     * @return array|null
     */
    private function getFlow(string $apiName): array|null {
        $url = $this->buildRequestUrl(
            "{base_url}/services/data/{$this->apiVersion}/actions/custom/flow/" . rawurlencode($apiName)
        );
        $flow = $this->request($url, []);

        if (is_string($flow)) {
            $this->logError($flow);
            return null;
        }

        return $flow;
    }

    /**
     * Retrieves a list of sf objects, optionally filtered using the current query.
     *
     * @return array|string
     */
    private function getObjects(): array|string {
        $url = $this->buildRequestUrl("{base_url}/services/data/{$this->apiVersion}/sobjects");
        $objects = $this->request($url, [], 'GET', 'sobjects');

        if (is_string($objects) || empty($this->currentQuery['wheres'])) {
            return $objects;
        }

        $filteredObjects = collect($objects);

        foreach ($this->currentQuery['wheres'] as $where) {
            $operator = strtoupper($where['operator']);

            if (in_array($operator, ['LIKE', 'NOT LIKE'], true)) {
                $isNotLike = $operator === 'NOT LIKE';
                $pattern = str_replace(
                    ['%', '_'],
                    ['.*', '.'],
                    preg_quote((string) $where['value'], '/')
                );

                $filteredObjects = $filteredObjects->filter(function ($object) use ($where, $pattern, $isNotLike): bool {
                    $value = data_get($object, $where['field']);

                    if (!is_scalar($value) && !$value instanceof \Stringable) {
                        return false;
                    }

                    $matches = preg_match('/^' . $pattern . '$/iu', (string) $value) === 1;
                    return $isNotLike ? !$matches : $matches;
                });

                continue;
            }

            $filteredObjects = $filteredObjects->where(
                $where['field'],
                $where['operator'],
                $where['value']
            );
        }

        return $filteredObjects->values()->all();
    }

    /**
     * Retrieves a single object if found.
     *
     * @param string $objectName
     *
     * @return array|string
     */
    private function getSingleObject(string $objectName): array|string {
        $url = $this->buildRequestUrl("{base_url}/services/data/{$this->apiVersion}/sobjects/{$objectName}/describe");
        return $this->request($url, []);
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
        
        return $this->request($url, ['q' => $query], 'GET', 'records');
    }

    // ===================================================================================
    // Posting Data
    // ===================================================================================

    /**
     * Creates a record of the selected object type, returning Salesforce's create result.
     * 
     * @param array $data The data for the record to create.
     *
     * @return object|null The created record object or null on failure.
     */
    public function create(array $data): ?object {
        $response = $this->writeRecord($data, 'POST');

        if ($response === null) {
            $this->logError('Failed to write record to Salesforce.');
            return null;
        }

        if (($response['success'] ?? null) !== true || !is_string($response['id'] ?? null) || $response['id'] === '') {
            $this->logError('Unexpected Salesforce record creation response.');
            return null;
        }

        return (object) $response;
    }

    /**
     * Updates the selected record, returning whether Salesforce accepted the update.
     * 
     * @param array $data The data for the record to update.
     *
     * @return bool True if the update was accepted by Salesforce, false otherwise.
     */
    public function update(array $data): bool {
        $response = $this->writeRecord($data, 'PATCH');

        if ($response === null) {
            $this->logError('Failed to write record to Salesforce.');
            return false;
        }

        if ($response !== []) {
            $this->logError('Unexpected Salesforce record update response.');
            return false;
        }

        return true;
    }

    /**
     * Writes a record to Salesforce using the specified HTTP method (POST for create, PATCH for update).
     *
     * @param array  $data
     * @param string $method
     *
     * @return array|null
     */
    private function writeRecord(array $data, string $method): ?array {
        if (!$this->hasActiveConnection()) {
            return null;
        }

        $possibleObject = $this->currentQuery['object'] ?? null;
        if ($possibleObject === null) {
            $this->logError("Object type not specified for {$method} request.");
            return null;
        }

        $object = $this->getQueryableObject($possibleObject);
        if ($object === null || $object['name'] === 'Object') {
            $this->logError("The object of type {$possibleObject} is not configured for record writes.");
            return null;
        }

        $recordId = $this->currentQuery['recordId'] ?? null;
        if ($method === 'PATCH' && (!is_string($recordId) || $recordId === '')) {
            $this->logError('A record id is required to update a Salesforce record.');
            return null;
        }
        if ($method === 'POST' && $recordId !== null) {
            $this->logError('A record id must not be selected when creating a Salesforce record.');
            return null;
        }

        $allowedFields = $object['fields'] === 'All' ? null : array_map('trim', explode(',', $object['fields']));
        foreach (array_keys($data) as $field) {
            if (!is_string($field) || $field === '') {
                $this->logError('Salesforce record data must be keyed by field API names.');
                return null;
            }
            if ($allowedFields !== null && !in_array($field, $allowedFields, true)) {
                $this->logError("The field '{$field}' is not configured for record writes on {$object['name']}.");
                return null;
            }
        }

        $objectName = rawurlencode($object['name']);
        $url = "{base_url}/services/data/{$this->apiVersion}/sobjects/{$objectName}";
        if ($method === 'PATCH') {
            $url .= '/' . rawurlencode($recordId);
        }

        $response = $this->request($this->buildRequestUrl($url), $data, $method);
        if (is_string($response)) {
            $this->logError($response);
            return null;
        }

        return $response;
    }

    // ===================================================================================
    // Flow Invocation
    // ===================================================================================

    /**
     * Invokes a configured flow, returning its parsed action result or a queued acknowledgement.
     * A successful invocation can still have a Waiting interview status.
     * 
     * @param string $flowName The name of the flow to invoke.
     * @param array $inputs The input values for the flow.
     * @param bool $awaitResponse Whether to wait for the flow's response or queue it for background execution.
     * 
     * @return array|string|null The parsed action result, a queued acknowledgement, or null if invocation failed.
     */
    public function invokeFlow(string $flowName, array $inputs, bool $awaitResponse = true): array|string|null {
        if ($this->hasActiveConnection() === false) {
            return null;
        }

        $flow = $this->getInvokableFlow($flowName);
        if ($flow === null) {
            $this->logError("Salesforce flow '{$flowName}' is not configured for invocation.");
            return null;
        }

        $inputSchema = $flow['sf_flow_inputs_cache'] ?? [];
        $preparedInputs = $this->prepareFlowInputs($flowName, $inputSchema, $inputs);
        if (is_string($preparedInputs)) {
            $this->logError("Salesforce flow '{$flowName}' invocation failed.", $preparedInputs);
            return $preparedInputs;
        }

        if (!$awaitResponse) {
            $connection = InvokeSalesforceFlow::CONNECTION;
            $driver = config("queue.connections.{$connection}.driver");
            if (!in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                $error = 'Background Salesforce flows require a durable asynchronous queue connection.';
                $this->logError("Salesforce flow '{$flowName}' invocation failed.", $error);
                return $error;
            }

            Queue::connection($connection)->push(new InvokeSalesforceFlow(
                $flowName,
                $preparedInputs,
                $this->getCurrentEnvironment()
            ));

            return ['queued' => true];
        }

        return $this->invokePreparedFlow($flowName, $preparedInputs, $flow['sf_flow_outputs_cache'] ?? []);
    }

    /**
     * Executes a queued invocation using the inputs already validated at dispatch.
     * Do not re-filter this snapshot against a long-lived worker's cached schema.
     * 
     * @param string $flowName The name of the flow to run.
     * @param array $preparedInputs The inputs already validated and prepared for the flow.
     *
     * @throws \RuntimeException If the flow cannot be run or fails during execution.
     */
    public function runQueuedFlow(string $flowName, array $preparedInputs): void {
        if (!$this->hasActiveConnection()) {
            throw new \RuntimeException('The queued Salesforce flow has no active connection.');
        }

        $flow = $this->getInvokableFlow($flowName, true);
        if ($flow === null) {
            throw new \RuntimeException("Salesforce flow '{$flowName}' is no longer configured for invocation.");
        }

        $result = $this->invokePreparedFlow($flowName, $preparedInputs, $flow['sf_flow_outputs_cache'] ?? []);
        if (is_string($result)) {
            throw new \RuntimeException($result);
        }
    }

    /**
     * Invokes a Salesforce flow with the prepared inputs and output schema.
     *
     * @param string $flowName The name of the flow to invoke.
     * @param array $preparedInputs The inputs already validated and prepared for the flow.
     * @param array $outputSchema The schema defining the expected output variables.
     *
     * @return array|string The result of the flow invocation, either as an array of output values or an error string.
     */
    private function invokePreparedFlow(string $flowName, array $preparedInputs, array $outputSchema): array|string {
        $result = $this->requestFlowInvocation($flowName, $preparedInputs);
        if (is_array($result)) {
            $outputs = $this->prepareFlowValues($flowName, $outputSchema, $result['outputValues'], 'output');
            if (is_string($outputs)) {
                $result = $outputs;
            } else {
                $result['outputValues'] = $outputs;
            }
        }

        if (is_string($result)) {
            $this->logError("Salesforce flow '{$flowName}' invocation failed.", $result);
        }

        return $result;
    }

    /**
     * Prepares an invokable flows inputs array based on the input schema and provided inputs.
     *
     * @param string $flowName
     * @param array  $inputSchema
     * @param array  $inputs
     *
     * @return array|string
     */
    private function prepareFlowInputs(string $flowName, array $inputSchema, array $inputs): array|string {
        return $this->prepareFlowValues($flowName, $inputSchema, $inputs, 'input');
    }

    /**
     * Maps Salesforce variable metadata to the PHP types used by Meros actions.
     * 
     * @param array $variable The Salesforce flow variable metadata.
     *
     * @return string The corresponding PHP type used by Meros actions.
     */
    public function getFlowVariableType(array $variable): string {
        $type = match (strtoupper($variable['type'] ?? '')) {
            'STRING', 'ID', 'PICKLIST', 'MULTIPICKLIST', 'DATE', 'DATETIME', 'TIME' => 'string',
            'BOOLEAN' => 'boolean',
            'INTEGER', 'INT', 'LONG' => 'integer',
            'NUMBER', 'DOUBLE', 'CURRENCY', 'PERCENT' => 'integer|double',
            'SOBJECT', 'APEX' => 'array',
            default => throw new \InvalidArgumentException('Unsupported Salesforce flow variable type: ' . ($variable['type'] ?? '(missing)')),
        };

        return (int) ($variable['maxOccurs'] ?? 1) !== 1 ? 'array' : $type;
    }

    private function prepareFlowValues(string $flowName, array $schema, array $values, string $direction): array|string {
        $prepared = [];

        foreach ($schema as $variable) {
            $name = $variable['name'] ?? null;
            if (!is_string($name) || $name === '') {
                return "Salesforce flow schema is missing a name for an {$direction} on flow '{$flowName}'.";
            }

            if (!array_key_exists($name, $values)) {
                if ($variable['required'] ?? false) {
                    return "Required {$direction} '{$name}' is missing for flow '{$flowName}'.";
                }
                continue;
            }

            try {
                $prepared[$name] = $this->parseFlowValue($variable, $values[$name]);
            } catch (\InvalidArgumentException $exception) {
                return "Invalid {$direction} '{$name}' for flow '{$flowName}': " . $exception->getMessage();
            }
        }

        return $prepared;
    }

    /**
     * Parses and validates a Salesforce flow variable value according to its metadata.
     *
     * @param array $variable The Salesforce flow variable metadata.
     * @param mixed $value The value to parse and validate.
     *
     * @return mixed The parsed and validated value.
     *
     * @throws \InvalidArgumentException If the value does not conform to the variable's metadata.
     */
    private function parseFlowValue(array $variable, mixed $value): mixed {
        $type = $this->getFlowVariableType($variable);
        if ($value === null) {
            if ($variable['required'] ?? false) {
                throw new \InvalidArgumentException('A non-null value is required.');
            }
            return null;
        }

        $maxOccurs = (int) ($variable['maxOccurs'] ?? 1);
        if ($maxOccurs !== 1) {
            if (!is_array($value) || !array_is_list($value) || ($maxOccurs > 1 && count($value) > $maxOccurs)) {
                throw new \InvalidArgumentException('Expected a collection within the declared maximum size.');
            }
            $variable['maxOccurs'] = 1;
            return array_map(fn ($item) => $this->parseFlowValue($variable, $item), $value);
        }

        if ($type === 'integer|double' && is_int($value)) {
            $value = (float) $value;
        }
        if (!in_array(gettype($value), explode('|', $type), true)) {
            throw new \InvalidArgumentException("Expected {$type}, received " . gettype($value) . '.');
        }

        if (is_array($variable['picklistValues'] ?? null)) {
            $allowed = array_map(
                fn ($option) => is_array($option) ? ($option['value'] ?? null) : $option,
                $variable['picklistValues']
            );
            if (!in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException('Value is not in the allowed picklist values.');
            }
        }

        return $value;
    }

    /**
     * Invokes a Salesforce flow with the given inputs.
     *
     * @param string $flowName The name of the Salesforce flow to invoke.
     * @param array  $inputs The input values for the flow.
     *
     * @return array|string The result of the flow invocation, either as an array of output values or an error string.
     */
    private function requestFlowInvocation(string $flowName, array $inputs): array|string {
        $url = $this->buildRequestUrl(
            "{base_url}/services/data/{$this->apiVersion}/actions/custom/flow/" . rawurlencode($flowName)
        );
        $response = $this->request($url, ['inputs' => [(object) $inputs]], 'POST');

        if (is_string($response)) {
            return $response;
        }

        if (count($response) !== 1 || !isset($response[0]) || !is_array($response[0])) {
            return 'Unexpected Salesforce flow invocation response.';
        }

        $result = $response[0];
        if (($result['isSuccess'] ?? null) === false) {
            $errors = [];
            foreach ($result['errors'] ?? [] as $error) {
                if (is_array($error)) {
                    $errors[] = ($error['statusCode'] ?? 'FLOW_ERROR') . ': ' . ($error['message'] ?? 'Flow invocation failed.');
                }
            }

            return $errors === [] ? 'Salesforce flow invocation failed.' : implode('; ', $errors);
        }

        if (($result['isSuccess'] ?? null) !== true || !is_array($result['outputValues'] ?? null)) {
            return 'Unexpected Salesforce flow invocation response.';
        }

        return $result;
    }

    // ===================================================================================
    // HTTP
    // ===================================================================================

    /**
     * Attempts a HTTP request with the given url, method and payload, attempting to refresh the integration's
     * access token if applicable.
     *
     * @param string $url
     * @param array  $payload
     * @param string $method
     * @param string $returnKey
     *
     * @return array|string
     */
    private function request(string $url, array $payload, string $method = 'GET', string $returnKey = ''): array|string {
        $result = $this->attemptRequest($url, $payload, $method, $returnKey);

        if (is_string($result) && $this->shouldRefreshToken($result)) {
            $newToken = $this->refreshToken();
            if ($newToken !== null) {
                $result = $this->attemptRequest($url, $payload, $method, $returnKey);
            }
        }

        return $result;
    }

    /**
     * Attempts a HTTP request with the given url, method and payload.
     *
     * @param string $url
     * @param array  $payload
     * @param string $method
     * @param string $returnKey
     *
     * @return array|string
     */
    private function attemptRequest(string $url, array $payload, string $method = 'GET', string $returnKey = ''): array|string {
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'])) {
            return 'Invalid HTTP method specified.';
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return 'Cannot locate access token for request.';
        }

        $response = $this->httpClient()->send([
            'method'  => $method,
            'url'     => $url,
            'payload' => $payload === [] && in_array($method, ['POST', 'PATCH'], true) ? (object) [] : $payload,
            'format'  => 'json',
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Accept'        => 'application/json',
            ],
        ]);

        if ($method === 'PATCH' && $response->status() === 204) {
            return [];
        }

        $body = $response->getBody();
        $decoded = json_decode($body, true);

        // Guard against non-JSON / empty bodies (error pages, network garbage)
        if (!is_array($decoded)) {
            return !$response->successful()
                ? 'Request failed. HTTP Status: ' . $response->status()
                : 'Unexpected non-JSON response from Salesforce.';
        }

        $error = $this->checkForResponseError($decoded);
        if ($error !== null) {
            return $error;
        }

        if (!$response->successful()) {
            return 'Request failed. HTTP Status: ' . $response->status();
        }

        return !empty($returnKey) ? $decoded[$returnKey] ?? [] : $decoded;
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
        $storedObjects = $this->settings('', $refresh)->getItemValue('sf_queryable_objects_' . $currentEnv);

        if (!is_array($storedObjects)) {
            return [];
        }

        $objects = [];
        
        foreach ($storedObjects as $value) {
            $object         = $value['sf_object_name'] ?? null;
            $objectNickname = $value['sf_object_nickname'] ?? '';
            $fields         = $value['sf_object_fields'] ?? '';
            $fieldsCache    = $value['sf_fields_cache'] ?? [];

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
                    'name'         => $object,
                    'label'        => empty($objectNickname) ? $object : $objectNickname,
                    'nickname'     => Str::snake(Str::replace('-', '_', $objectNickname)),
                    'fields'       => $fields === 'All' ? 'All' : implode(',', $fields),
                    'fields_cache' => $fieldsCache
                ];
            }
        }

        return array_merge($objects, [
            'Object' => [
                'name'     => 'Object',
                'nickname' => '',
                'fields'   => 'All'
            ]
        ]);
    }

    /**
     * Returns a list of queryable Salesforce objects, excluding the generic 'Object' entry.
     *
     * @return array
     */
    public function getQueryableObjectsList(): array {
        return collect($this->getQueryableObjects())
            ->where(function ($object) {
                return $object['name'] !== 'Object';
            })
            ->keyBy('name')
            ->mapWithKeys(fn ($object, $name) => [$name => $name])
            ->all();
    }

    /**
     * Retrieves a single object from the queryable objects array, if it exists.
     *
     * @param string $objectName
     * @param bool   $refresh
     *
     * @return array|null
     */
    private function getQueryableObject(string $objectName, bool $refresh = false): ?array {
        $objects = $this->getQueryableObjects($refresh);

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
        
        return $objects->firstWhere('nickname', $nickname) ?? $objects->firstWhere('nickname', Str::lower($nickname));
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
}