<?php

namespace App\Domain\Model;

/** Sex as asked in onboarding (ONB-004); it selects the body-fat buckets (TBL-001). */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
}
