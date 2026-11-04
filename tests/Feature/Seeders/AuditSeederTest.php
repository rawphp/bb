<?php

use App\Models\Phase;
use App\Models\Plan;
use App\Models\User;
use Tests\Support\AuditSeeder;

it('PLAN-003 gives the e2e audit user a phase covering today, so plan_show is not empty', function () {
    $this->seed();
    $this->seed(AuditSeeder::class);
    $user = User::query()->where('email', AuditSeeder::EMAIL)->sole();
    $phase = Phase::query()->where('user_id', $user->id)->sole();

    $data = $this->actingAs($user)->postJson('/capabilities/plan_show')->assertOk()->json('data');

    expect($phase->start_date->toDateString())->toBe(Plan::query()->where('user_id', $user->id)->sole()->start_date->toDateString())
        ->and(array_column($data['phases'], 'id'))->toBe([$phase->id])
        ->and($data['phases'][0]['state'])->toBe('current');
});
