<?php

namespace App\Capabilities\Support;

/**
 * Field's enum cannot list null, so without this a nullable enum property
 * refuses the null its type allows, both in an input and in an output check.
 * It adds null to the enum of every nullable property of the class, as the
 * generated TS types already say. A nested DTO uses it on its own class.
 */
trait AcceptsNullEnums
{
    /** @return array<string, mixed> */
    public static function jsonSchema(): array
    {
        $schema = parent::jsonSchema();

        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (isset($property['enum']) && in_array('null', (array) ($property['type'] ?? []), true)) {
                $schema['properties'][$key]['enum'][] = null;
            }
        }

        return $schema;
    }
}
