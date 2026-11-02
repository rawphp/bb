<?php

namespace App\Enums;

enum PhaseFit: string
{
    case Cut = 'cut';
    case Gain = 'gain';
    case Maintain = 'maintain';
}
