<?php

namespace Database\Factories;

use App\Models\AuditRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditRecord> */
class AuditRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => 'capability.invoked',
            'capability' => 'weigh_in_log',
            'channel' => 'ui',
            'input' => ['weight_kg' => 81.4],
            'result' => ['ok' => true, 'summary' => ['invalidates' => [], 'toast' => null, 'undo' => null]],
            'created_at' => now(),
        ];
    }
}
