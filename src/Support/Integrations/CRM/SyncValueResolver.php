<?php

namespace MM\Meros\Crm\Support\Integrations\CRM;

use MM\Meros\Support\MergeFields;

/**
 * Resolves values for synchronization mappings based on the provided submission data and mapping configuration.
 */
final class SyncValueResolver {
    /**
     * Resolves a value based on the provided mapping configuration and submission data.
     *
     * @param array $mapping The mapping configuration containing the source and optional fallback.
     * @param array $submission The submission data used to resolve values.
     *
     * @return mixed The resolved value based on the mapping and submission data.
     */
    public function resolve(array $mapping, array $submission): mixed {
        $source   = trim((string) ($mapping['source'] ?? ''));
        $fallback = $mapping['fallback'] ?? null;

        if ($source === '') {
            return $fallback;
        }

        if (str_starts_with($source, 'form:')) {
            $fieldName = substr($source, 5);
            $value = $submission[$fieldName] ?? null;

            return $this->normalize($value, $fallback);
        }

        if (str_starts_with($source, 'merge:')) {
            $mergeKey = substr($source, 6);
            $resolved = MergeFields::get()->resolve($mergeKey, 'string');

            return $this->normalize($resolved, $fallback);
        }

        if (str_starts_with($source, 'value:')) {
            return substr($source, 6);
        }

        return $this->normalize($source, $fallback);
    }

    /**
     * Normalizes a value by returning the fallback if the value is null or an empty string.
     *
     * @param mixed $value The value to normalize.
     * @param mixed $fallback The fallback value to return if the value is null or empty.
     *
     * @return mixed The normalized value or the fallback.
     */
    private function normalize(mixed $value, mixed $fallback = null): mixed {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return $value;
    }
}
