<?php

namespace WF\API\Guardrail\Service\DataSources\Trait;


use Exception;

/**
 * class trait Data Source Resolver
 *
 * Retrieves values from $this->dataSources by property.
 */
trait DataSourceResolver
{
    /**
     * Retrieves values from $this->dataSources by property.
     *
     * NOTE.... The established DOT notation in a rule is enforced here.
     * ... but ... could also be changed here if needed.
     *
     * @param string $propertyPath
     * @return mixed
     * @throws Exception
     */
    public function getValueFromDataSource(string $propertyPath): mixed
    {
        $propertyPath = json_decode($propertyPath, true);
        if (
            (is_array($propertyPath) && (count($propertyPath) > 1)) ||
            empty($propertyPath[0])
        ) {
            throw new Exception(
                "Lookup Single Value Failed! Invalid property path |"
                . json_encode($propertyPath ?? '{}')
            );
        }

        $pathParts = explode('.', $propertyPath[0]);

        $dataSourceKey = $pathParts[0];
        if (!array_key_exists($dataSourceKey, $this->dataSources)) {
            throw new Exception("Lookup Single Value Failed! Unknown '$dataSourceKey'");
        }
        unset($pathParts[0]);

        $dataSource = $this->dataSources[$dataSourceKey];
        $current = $dataSource;

        foreach ($pathParts as $part) {
            if (!is_object($current) || !property_exists($current, $part)) {
                throw new Exception(
                   "Lookup Single Value Failed! Invalid property path "
                    . json_encode($propertyPath)
                   . " – missing part '$part'");
            }

            $current = $current->$part;
        }

        return $current;
    }

    /**
     * @param string $propertyPath
     * @return array
     * @throws Exception
     */
    public function getMultipleValuesFromMultipleDataSources(string $propertyPath): array
    {
        $propertyPath = json_decode($propertyPath, true);
        if (!is_array($propertyPath) || (count($propertyPath) === 1)) {
            throw new Exception("Multi Value Lookup Failed! Invalid property path" . json_encode($propertyPath));
        }

        $resolvedValues = [];
        foreach ($propertyPath as $part) {
           $searchString = '["' . $part . '"]'; ;
            $resolvedValues[$part] = $this->getValueFromDataSource($searchString);
        }

        return $resolvedValues;
    }
}
