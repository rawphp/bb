<?php

namespace App\Capabilities\Support;

use ReflectionClass;

/**
 * A nullable property takes an explicit null. The package's schema leaves
 * null out of a nullable property's enum, so the null its type allows fails
 * the enum check, in an input and in an output check; and it drops every
 * nullable key from `required`, so a missing key passes as null. This adds
 * null to each nullable enum and keeps a nullable property with no default
 * required: where null means something (Off, Gain first), it must be sent.
 * A nested DTO uses it on its own class.
 */
trait ExplicitNulls
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

        foreach ((new ReflectionClass(static::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->allowsNull() && ! $parameter->isDefaultValueAvailable() && ! in_array($parameter->getName(), $schema['required'] ?? [], true)) {
                $schema['required'][] = $parameter->getName();
            }
        }

        return $schema;
    }
}
