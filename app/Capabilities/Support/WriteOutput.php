<?php

namespace App\Capabilities\Support;

use InvalidArgumentException;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A write output that returns more than WriteResult (CAP-010). The subclass
 * takes the WriteResult as its promoted `result` parameter, next to its own fields.
 * On the wire, WriteResult's fields stand at the top level beside them, as
 * the client reads `invalidates` there. So CAP-010 is declared only in WriteResult.
 */
abstract class WriteOutput extends CapabilityData
{
    public static function jsonSchema(): array
    {
        $schema = parent::jsonSchema();
        $result = WriteResult::jsonSchema();

        unset($schema['properties']['result']);
        $schema['properties'] = $result['properties'] + $schema['properties'];
        $schema['required'] = [...$result['required'], ...array_values(array_diff($schema['required'] ?? [], ['result']))];

        return $schema;
    }

    public static function fromArray(array $data, bool $allowAdditionalProperties = false): static
    {
        $fields = array_flip(array_keys(WriteResult::jsonSchema()['properties']));

        if (array_key_exists('result', $data) && ! $allowAdditionalProperties) {
            throw new InvalidArgumentException(static::class.' has no "result" on the wire; WriteResult\'s fields stand at the top level');
        }

        return parent::fromArray(['result' => array_intersect_key($data, $fields)] + array_diff_key($data, $fields), $allowAdditionalProperties);
    }

    public function toArray(): array
    {
        $out = parent::toArray();
        $result = $out['result'];
        unset($out['result']);

        return $result + $out;
    }
}
