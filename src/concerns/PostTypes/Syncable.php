<?php

namespace MM\Meros\Crm\Concerns\PostTypes;


trait Syncable {
    final public bool $syncable = true;
    protected array $syncsWith = [];

    private function syncsWith(string $service): void {
        if (!in_array($service, $this->syncsWith)) {
            $this->syncsWith[] = $service;
        }
    }

    final public function getSyncServices(): array {
        return $this->syncsWith;
    }
}