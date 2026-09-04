<?php

namespace MM\Meros\Crm\App\Integrations\ExternalModels;

use MM\Meros\Services\Contracts\Integrations\ExternalModel;

class SalesforceObjectRecords extends ExternalModel {
	protected string $integrationID = 'salesforce';

	protected string $objectApiName = 'Contact';

	protected function __construct() {
		parent::__construct();

		if ($this->integration !== null) {
			$this->baseUri($this->integration->getBaseUri());
		}
	}

	public static function forObject(string $objectApiName, string $connectionId = 'default'): static {
		$instance = new static();

		if ($connectionId !== '') {
			$instance->withConnection($connectionId);
		}

		return $instance->object($objectApiName);
	}

	public function object(string $objectApiName): static {
		$normalised = $this->normaliseObjectApiName($objectApiName);

		if ($normalised !== '') {
			$this->objectApiName = $normalised;
		}

		return $this;
	}

	public function getObject(): string {
		return $this->objectApiName;
	}

	protected function resolveEndpointFor(string $method, array $context = []): string {
		$method = strtolower($method);

		if ($method === 'get') {
			return $this->salesforceDataPath() . '/query';
		}

		if (in_array($method, ['post', 'create'], true)) {
			return $this->salesforceDataPath() . '/sobjects/' . $this->objectApiName;
		}

		return parent::resolveEndpointFor($method, $context);
	}

	protected function formatQuery(array $query): array {
		$fields = $this->resolveSelectedFields($query);
		$soql = 'SELECT ' . implode(', ', $fields) . ' FROM ' . $this->objectApiName;

		$clauses = $this->buildWhereClauses($query);

		if ($clauses !== []) {
			$soql .= ' WHERE ' . implode(' AND ', $clauses);
		}

		if (isset($query['limit']) && is_numeric($query['limit'])) {
			$soql .= ' LIMIT ' . max(1, (int) $query['limit']);
		}

		return [
			'q' => $soql,
		];
	}

	protected function formatPayload(array $payload, string $method): array {
		if (strtoupper($method) === 'POST') {
			return $payload;
		}

		return parent::formatPayload($payload, $method);
	}

	protected function resolveSelectedFields(array $query): array {
		$fields = $query['fields'] ?? 'Id';

		if (is_string($fields)) {
			$fields = array_filter(array_map('trim', explode(',', $fields)));
		}

		$fields = array_values(array_filter($fields, static fn ($field) => is_string($field) && trim($field) !== ''));

		return $fields !== [] ? $fields : ['Id'];
	}

	protected function buildWhereClauses(array $query): array {
		$clauses = [];

		foreach ($query as $key => $value) {
			if (in_array($key, ['fields', 'limit', 'filters'], true)) {
				continue;
			}

			if (!is_string($key) || $key === '') {
				continue;
			}

			$clauses[] = $key . ' = ' . $this->toSoqlValue($value);
		}

		$filters = $query['filters'] ?? [];

		if (!is_array($filters)) {
			return $clauses;
		}

		foreach ($filters as $filter) {
			if (!is_array($filter)) {
				continue;
			}

			$field = trim((string) ($filter['field'] ?? ''));
			$operator = strtoupper(trim((string) ($filter['operator'] ?? '=')));
			$value = $filter['value'] ?? null;

			if ($field === '') {
				continue;
			}

			if (in_array($operator, ['IN', 'NOT IN'], true)) {
				$values = is_array($value) ? $value : [$value];
				$clauses[] = $field . ' ' . $operator . ' (' . implode(', ', array_map(fn ($item) => $this->toSoqlValue($item), $values)) . ')';
				continue;
			}

			$clauses[] = $field . ' ' . $operator . ' ' . $this->toSoqlValue($value);
		}

		return $clauses;
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

		$escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value);

		return "'" . $escaped . "'";
	}

	protected function salesforceDataPath(): string {
		$apiVersion = '';

		if ($this->integration !== null) {
			$apiVersion = trim((string) ($this->integration->getApiVersion() ?: $this->integration->getSetting('api_version', '')));
		}

		$path = '/services/data';

		if ($apiVersion !== '') {
			$path .= '/' . ltrim($apiVersion, '/');
		}

		return $path;
	}

	protected function normaliseObjectApiName(string $objectApiName): string {
		return preg_replace('/[^A-Za-z0-9_]/', '', trim($objectApiName)) ?: '';
	}
}
