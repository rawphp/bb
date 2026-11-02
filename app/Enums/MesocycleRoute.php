<?php

namespace App\Enums;

/** MESO-002: the ways a mesocycle is created, in the + menu's order. A draft stores the one it came from. */
enum MesocycleRoute: string
{
    case Template = 'template';
    case CopyPrevious = 'copy_previous';
    case Builder = 'builder';
    case Scratch = 'scratch';

    public function title(): string
    {
        return match ($this) {
            self::Template => 'Template',
            self::CopyPrevious => 'Copy previous mesocycle',
            self::Builder => 'Meso Builder',
            self::Scratch => 'Build from scratch',
        };
    }
}
