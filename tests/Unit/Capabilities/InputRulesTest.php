<?php

use Rawphp\Capabilities\Schema\ServerRuleClassifier;
use Rawphp\Capabilities\Support\CapabilityData;

// The package runs an input's rules() after its JSON schema and drops every rule
// it counts as portable (date_format, integer, between, …): the schema is meant to
// carry those. A portable rule in rules() is never checked, so none may be there.

/** @return list<class-string<CapabilityData>> */
function capabilityDataClasses(): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Capabilities')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path()) + 1));
        if (class_exists($class) && is_subclass_of($class, CapabilityData::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('CAP-012 keeps only rules the invoke checks in an input\'s rules()', function () {
    $classifier = new ServerRuleClassifier;
    $dropped = [];

    foreach (capabilityDataClasses() as $class) {
        foreach ($class::rules() as $field => $rules) {
            foreach (is_array($rules) ? $rules : explode('|', $rules) as $rule) {
                if (is_string($rule) && $rule !== 'nullable' && $classifier->isPortable($rule)) {
                    $dropped[] = "{$class} {$field}: {$rule}";
                }
            }
        }
    }

    expect($dropped)->toBe([], 'Use the schema (#[Field]) or a rule object, such as Rule::date()->format(\'H:i\')');
});

it('CAP-012 keeps rules on the top-level input, the only rules() the invoke runs', function () {
    $nested = array_filter(
        capabilityDataClasses(),
        fn (string $class) => ! str_ends_with($class, 'Input') && $class::rules() !== [],
    );

    expect(array_values($nested))->toBe([], 'Move them to the input as "<list>.*.<field>"');
});
