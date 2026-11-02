<?php

namespace App\Domain\Mesocycle\MesoBuilder;

/** MESO-021: going past the phase's suggestion warns and never blocks. */
interface Warning
{
    public function message(): string;
}
