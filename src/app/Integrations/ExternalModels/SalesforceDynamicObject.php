<?php

namespace MM\Meros\Crm\App\Integrations\ExternalModels;

class SalesforceDynamicObject extends SalesforceObject {
    protected string $resolvedObjectName = 'Contact';

    public static function forObject(string $objectApiName, string $connectionLabel = ''): static {
        $instance = static::init($connectionLabel);

        return $instance->object($objectApiName);
    }

    public function object(string $objectApiName): static {
        $normalised = $this->normaliseObjectApiName($objectApiName);

        if ($normalised !== '') {
            $this->resolvedObjectName = $normalised;
            $this->path('sobjects/' . $this->resolvedObjectName);
        }

        return $this;
    }

    protected function objectName(): string {
        return $this->resolvedObjectName;
    }

    private function normaliseObjectApiName(string $objectApiName): string {
        return preg_replace('/[^A-Za-z0-9_]/', '', trim($objectApiName)) ?: '';
    }
}