<?php

namespace MM\Meros\Crm\App\Integrations\ExternalModels;

use InvalidArgumentException;
use MM\Meros\Support\ExternalModel;

/**
 * Base Salesforce external model with Laravel-like query helpers.
 */
abstract class SalesforceObject extends ExternalModel {
    /**
     * @var array<int, array{field:string,operator:string,value:mixed}>
     */
    protected array $wheres = [];

    /**
     * @var array<int, string>
     */
    protected array $selects = ['Id'];

    protected ?int $limitValue = null;

    public function __construct() {
        parent::__construct();

        $this->integration('salesforce')
            ->usingEnvironment('production')
            ->path('sobjects/' . $this->objectName());
    }

    /**
     * Returns the Salesforce object API name, e.g. Contact or Account.
     */
    abstract protected function objectName(): string;

    /**
     * Selects a specific integration connection label.
     */
    public function usingConnection(string $label): static {
        $this->using($label);

        return $this;
    }

    /**
     * Adds a simple where constraint.
     */
    public function where(string $field, mixed $operatorOrValue, mixed $value = null): static {
        $operator = '=';
        $resolvedValue = $operatorOrValue;

        if ($value !== null) {
            $operator = strtoupper((string) $operatorOrValue);
            $resolvedValue = $value;
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $resolvedValue,
        ];

        return $this;
    }

    /**
     * Sets selected fields for query retrieval.
     *
     * @param array<int, string> $fields
     */
    public function select(array $fields): static {
        $fields = array_values(array_filter(array_map('trim', $fields), fn ($field) => $field !== ''));

        if ($fields !== []) {
            $this->selects = $fields;
        }

        return $this;
    }

    /**
     * Sets a limit for query retrieval.
     */
    public function limit(int $limit): static {
        $this->limitValue = max(1, $limit);

        return $this;
    }

    /**
     * Gets object records by SOQL filters or by id.
     */
    public function get(string $id = ''): array {
        if (trim($id) !== '') {
            $this->resetHttpState();

            $response = parent::get($id);
            $this->throwIfFailed($response, 'get Salesforce ' . $this->objectName() . ' [' . $id . ']');

            return $response->json() ?? [];
        }

        $soql = $this->buildSoql();

        $records = $this->withPath('query', function () use ($soql): array {
            $this->resetHttpState();

            $this->asJson()->query(['q' => $soql]);

            $response = parent::get();

            $this->throwIfFailed($response, 'query Salesforce ' . $this->objectName() . ' records');

            $payload = $response->json() ?? [];

            if (!is_array($payload['records'] ?? null)) {
                return [];
            }

            return $payload['records'];
        });

        $this->resetQueryState();

        return is_array($records) ? $records : [];
    }

    /**
     * Returns the first matching record or null.
     */
    public function first(): ?array {
        $records = $this->limit(1)->get();

        $first = $records[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * Creates a Salesforce object record.
     */
    public function create(array $attributes): array {
        $this->resetHttpState();

        $response = $this->asJson()
            ->payload($attributes)
            ->post();

        $this->throwIfFailed($response, 'create Salesforce ' . $this->objectName());

        return $response->json() ?? [];
    }

    /**
     * Deletes a Salesforce object record by id.
     */
    public function delete(string $id = ''): bool {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A Salesforce record id is required for delete().');
        }

        $this->resetHttpState();

        $response = parent::delete($id);
        $this->throwIfFailed($response, 'delete Salesforce ' . $this->objectName() . ' [' . $id . ']');

        return true;
    }

    /**
     * Finds an object by id.
     */
    public function find(string $id): ?array {
        $record = $this->get($id);

        return $record === [] ? null : $record;
    }

    /**
     * Returns all object records (optionally limited by prior constraints).
     */
    public function all(): array {
        return $this->get();
    }

    protected function buildSoql(): string {
        $fields = implode(', ', $this->selects);
        $soql = 'SELECT ' . $fields . ' FROM ' . $this->objectName();

        if ($this->wheres !== []) {
            $clauses = array_map(function (array $where): string {
                $operator = strtoupper($where['operator']);

                if (in_array($operator, ['IN', 'NOT IN'], true)) {
                    $values = is_array($where['value']) ? $where['value'] : [$where['value']];
                    $formattedValues = implode(', ', array_map(fn ($item) => $this->toSoqlValue($item), $values));

                    return $where['field'] . ' ' . $operator . ' (' . $formattedValues . ')';
                }

                return $where['field'] . ' ' . $operator . ' ' . $this->toSoqlValue($where['value']);
            }, $this->wheres);

            $soql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($this->limitValue !== null) {
            $soql .= ' LIMIT ' . $this->limitValue;
        }

        return $soql;
    }

    protected function toSoqlValue(mixed $value): string {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $escaped = str_replace("'", "\\'", (string) $value);

        return "'" . $escaped . "'";
    }

    protected function resetHttpState(): void {
        $this->query = [];
        $this->headers = [];
        $this->payload = [];
        $this->format = 'json';
    }

    protected function resetQueryState(): void {
        $this->wheres = [];
        $this->selects = ['Id'];
        $this->limitValue = null;
    }
}
