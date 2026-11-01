<?php

namespace App\Enums;

/** PH-001, PH-002. */
enum PhaseType: string
{
    case FatLoss = 'fat_loss';
    case MiniCut = 'mini_cut';
    case Maintenance = 'maintenance';
    case MuscleGain = 'muscle_gain';
}
