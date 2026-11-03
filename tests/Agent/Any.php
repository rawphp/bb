<?php

namespace Tests\Agent;

use Illuminate\Support\Str;

/**
 * An argument the model makes up, such as the id of a row it adds. The fake model sends the example;
 * a live answer passes with any value there.
 */
final class Any
{
    public function __construct(public readonly mixed $example) {}

    /** A new lowercase ULID, as the app's client-made ids are. */
    public static function id(): self
    {
        return new self(strtolower((string) Str::ulid()));
    }
}
