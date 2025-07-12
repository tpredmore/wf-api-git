<?php

namespace WF\API\Accounting\Traits;

use InvalidArgumentException;

trait ApplicationsTrait {

    /**
     * Filter JSON string so that only specified keys are kept.
     *
     * @param string $json The JSON string to filter.
     * @param array  $allowedKeys The keys that should be retained.
     *
     * @return mixed The filtered JSON string.
     */
    public function filterJsonKeys(mixed $json, array $allowedKeys): mixed {
        // Decode JSON into an associative array.
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON provided: ' . json_last_error_msg());
        }

        // Recursively filter the data.
        // Encode the filtered array back to a JSON string.
        return self::filterData($data, $allowedKeys);
    }

    /**
     * Recursively filter an array (or object) so that only specified keys are kept.
     *
     * @param mixed $data The input data (decoded JSON, array or scalar).
     * @param array $allowedKeys An array of keys that you want to keep.
     * @return mixed The filtered data.
     */
    public function filterData(mixed $data, array $allowedKeys): mixed {
        // If it's an associative array or object, process keys accordingly.
        if (is_array($data)) {
            $filtered = [];
            // Determine if this array is associative.
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);
            foreach ($data as $key => $value) {
                // If the array is associative and the key is in allowedKeys, keep it.
                // For a numerically indexed array (e.g. list items), just process the values.
                if (!$isAssoc) {
                    $filtered[] = self::filterData($value, $allowedKeys);
                } else {
                    if (in_array($key, $allowedKeys, true)) {
                        $filtered[$key] = self::filterData($value, $allowedKeys);
                    }
                }
            }
            return $filtered;
        }
        // For any non-array (scalar) value, just return it.
        return $data;
    }

}
