<?php

use App\Capabilities\Support\HostDefinition;
use App\Domain\Notifications\ReminderKind;
use App\Models\NotificationPref;
use App\Models\User;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// notification_prefs_set over HTTP: the seam, and NOTIF-007's switch on the database.

function prefsSet(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/notification_prefs_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('NOTIF-007 registers notification_prefs_set on every host surface as a write that changes settings_show', function () {
    $definition = app(CapabilityRegistry::class)->get('notification_prefs_set');

    expect($definition->surfaces)->toEqualCanonicalizing(HostDefinition::SURFACES)
        ->and($definition->readOnly)->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(['settings_show'])
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and([$definition->cliDomain, $definition->cliVerb])->toBe(['notification', 'prefs-set'])
        ->and($definition->description)->not->toBe('');
});

it('NOTIF-007 refuses notification_prefs_set when signed out', function () {
    prefsSet(null, ['kind' => 'meal', 'on' => true])->assertUnauthorized();
});

it('NOTIF-007 answers 422 naming kind when it is not a reminder type', function () {
    prefsSet(User::factory()->create(), ['kind' => 'birthday', 'on' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'kind');
    expect(NotificationPref::query()->count())->toBe(0);
});

it('NOTIF-007 turning a switch on and off again keeps one row for that kind', function () {
    $user = User::factory()->create();

    prefsSet($user, ['kind' => 'meal', 'on' => true])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['settings_show{}']);
    expect(NotificationPref::switches($user)->isOn(ReminderKind::Meal))->toBeTrue();

    prefsSet($user, ['kind' => 'meal', 'on' => false])->assertOk();
    expect(NotificationPref::switches($user)->isOn(ReminderKind::Meal))->toBeFalse()
        ->and(NotificationPref::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('NOTIF-007 a switch is the user\'s own: another user keeps the defaults', function () {
    [$user, $other] = User::factory()->count(2)->create();

    prefsSet($user, ['kind' => 'weigh_in', 'on' => false])->assertOk();
    prefsSet($user, ['kind' => 'meal', 'on' => true])->assertOk();

    expect(NotificationPref::switches($other)->isOn(ReminderKind::WeighIn))->toBeTrue()
        ->and(NotificationPref::switches($other)->isOn(ReminderKind::Meal))->toBeFalse()
        ->and(NotificationPref::switches($user)->isOn(ReminderKind::WeighIn))->toBeFalse()
        ->and(NotificationPref::switches($user)->isOn(ReminderKind::Workout))->toBeTrue();
});

it('ACC-005 a user\'s switches go with the account', function () {
    $user = User::factory()->create();
    prefsSet($user, ['kind' => 'meal', 'on' => true])->assertOk();

    $user->delete();

    expect(NotificationPref::query()->count())->toBe(0);
});
