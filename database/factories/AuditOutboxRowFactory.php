<?php

namespace Database\Factories;

use App\Models\AuditOutboxRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuditOutboxRow> */
class AuditOutboxRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'event' => 'capability.invoked',
            'capability_name' => 'weigh_in_log',
            'payload_json' => ['event' => 'capability.invoked', 'actor_type' => null, 'actor_id' => null, 'redacted_input' => ['weight_kg' => 81.4]],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** Written for this user, who is named in the payload as the package's writer did. */
    public function actor(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload_json' => ['actor_type' => 'user', 'actor_id' => (string) $user->id] + $attributes['payload_json'],
        ]);
    }
}
