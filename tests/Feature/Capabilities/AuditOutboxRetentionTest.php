<?php

use App\Models\AuditOutboxRow;
use App\Models\AuditRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** A row as the package's writer left it in capabilities_audit_outbox before audit_records. */
function legacyAuditRow(?User $actor, CarbonInterface $at): string
{
    $factory = AuditOutboxRow::factory();

    return ($actor === null ? $factory : $factory->actor($actor))->create(['created_at' => $at, 'updated_at' => $at])->id;
}

it('CAP-022 prunes the old audit outbox to 90 days with audit_records, daily', function () {
    $user = User::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-02T03:00:00Z'));
    $kept = legacyAuditRow($user, now()->subDays(90)->addMinute());
    legacyAuditRow($user, now()->subDays(90)->subMinute());
    legacyAuditRow(null, now()->subDays(91));

    Artisan::call('model:prune', ['--model' => [AuditOutboxRow::class]]);

    expect(DB::table('capabilities_audit_outbox')->pluck('id')->all())->toBe([$kept]);
    $prune = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'AuditRecord'))
        ->sole();
    expect($prune->command)->toContain('AuditOutboxRow')
        ->and($prune->expression)->toBe('0 0 * * *');
});

it('ACC-005 deletes a user\'s old audit outbox rows with the account, and no one else\'s', function () {
    [$user, $other] = User::factory()->count(2)->create();
    legacyAuditRow($user, CarbonImmutable::now());
    $others = legacyAuditRow($other, CarbonImmutable::now());
    $system = legacyAuditRow(null, CarbonImmutable::now());
    AuditRecord::factory()->for($user)->create();

    $user->delete();

    expect(DB::table('capabilities_audit_outbox')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$others, $system])->sort()->values()->all())
        ->and(AuditRecord::query()->count())->toBe(0);
});
