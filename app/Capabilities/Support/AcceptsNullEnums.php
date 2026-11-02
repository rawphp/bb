<?php

namespace App\Capabilities\Support;

/**
 * Field's enum cannot list null, so without this an optional enum input
 * refuses an explicit null that its type allows. It adds null to the enum of
 * every nullable top-level property: sending null and leaving the field out
 * then mean the same, as the generated TS types say.
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
