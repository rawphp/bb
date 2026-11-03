<?php

use App\Actions\Settings\ShowHealthBridge;
use App\Domain\Tokens\TokenScope;
use App\Models\AuditRecord;
use App\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;

// The Health bridge page's rules (INT-001, INT-004), on in-memory rows: no database.

const BRIDGE_ENDPOINT = 'https://bb.example/capabilities';

function bridgeToken(array $attributes = []): PersonalAccessToken
{
    $token = new PersonalAccessToken;
    $token->forceFill($attributes + [
        'id' => '01JBQ2W8E4R6T8Y0U2I4O6P8A0',
        'name' => 'Health bridge',
        'scope' => TokenScope::HealthBridge,
        'abilities' => TokenScope::HEALTH_BRIDGE,
        'expires_at' => CarbonImmutable::parse('2027-10-03T09:00:00Z'),
        'last_used_at' => null,
        'created_at' => CarbonImmutable::parse('2026-10-01T09:00:00Z'),
    ]);

    return $token;
}

/** A bridge invoke as AuditRecordWriter stores it. */
function bridgePost(string $capability, array $input, string $at, bool $ok = true): AuditRecord
{
    $record = new AuditRecord;
    $record->forceFill([
        'capability' => $capability,
        'input' => $input,
        'result' => $ok ? ['ok' => true] : ['ok' => false, 'code' => 'validation_failed'],
        'created_at' => CarbonImmutable::parse($at),
    ]);

    return $record;
}

function bridgeWeight(string $at, float $value = 81.4, string $unit = 'kg', string $weighedAt = '2026-11-02T06:00:00+00:00'): AuditRecord
{
    return bridgePost('weigh_in_log', ['id' => '01JBQ2W8E4R6T8Y0U2I4O6P8A1', 'weighed_at' => $weighedAt, 'weight' => ['value' => $value, 'unit' => $unit]], $at);
}

function bridgeSteps(string $at, int $steps = 8412, string $date = '2026-11-01'): AuditRecord
{
    return bridgePost('steps_log', ['date' => $date, 'steps' => $steps], $at);
}

/** @param  list<AuditRecord>  $posts */
function bridgePage(?PersonalAccessToken $token, array $posts = [], string $now = '2026-11-02T09:00:00Z', string $shortcut = 'https://www.icloud.com/shortcuts/abc'): array
{
    return ShowHealthBridge::page($token, $posts, CarbonImmutable::parse($now), $shortcut, BRIDGE_ENDPOINT)->toArray();
}

it('INT-001 says what the bridge token can and cannot do', function () {
    expect(bridgePage(null)['scope_text'])
        ->toBe('The token can log weigh-ins and steps. It cannot read anything or change anything else.');
});

it('INT-001 shows the bridge as not set up until the user has a Health bridge token', function () {
    $page = bridgePage(null);

    expect($page['status'])->toBe(['text' => 'Not set up', 'tone' => 'neutral'])
        ->and($page['token'])->toBeNull()
        ->and($page['last_post'])->toBeNull();
});

it('INT-001 shows the token without its secret, with its expiry and when it was last used', function () {
    $page = bridgePage(bridgeToken(['last_used_at' => CarbonImmutable::parse('2026-11-02T06:02:00Z')]));

    expect($page['token'])->toBe([
        'id' => '01JBQ2W8E4R6T8Y0U2I4O6P8A0',
        'name' => 'Health bridge',
        'expires_text' => 'Expires 3 October 2027',
        'last_used_text' => 'Last used today, 06:02',
    ]);
});

it('INT-001 links the shared Shortcut from config, and writes out its steps against this server', function () {
    $page = bridgePage(null, shortcut: 'https://www.icloud.com/shortcuts/abc');
    $steps = implode("\n", array_column($page['steps'], 'text'));

    expect($page['shortcut_url'])->toBe('https://www.icloud.com/shortcuts/abc')
        ->and($page['steps'])->each->toHaveKey('text')
        ->and($steps)->toContain(BRIDGE_ENDPOINT.'/weigh_in_log')
        ->toContain(BRIDGE_ENDPOINT.'/steps_log')
        ->toContain('Authorization')
        ->toContain('weighed_at')
        ->toContain('"date"')
        ->toContain('"steps"')
        // Shortcuts has no ULID action: the id is built from actions it has (CAP-019).
        ->toContain('yyyyMMddHHmmss')
        ->toContain('Random Number');
});

it('INT-001 says the token has expired, since the Shortcut can no longer post with it', function () {
    $page = bridgePage(bridgeToken(['expires_at' => CarbonImmutable::parse('2026-11-01T00:00:00Z')]), [bridgeSteps('2026-11-02T06:02:00Z')]);

    expect($page['status'])->toBe(['text' => 'The token has expired. Revoke it and set up the bridge again.', 'tone' => 'warning'])
        ->and($page['token']['expires_text'])->toBe('Expired 1 November 2026');
});

it('INT-004 waits for the first post once the token exists', function () {
    expect(bridgePage(bridgeToken())['status'])->toBe(['text' => 'Waiting for the first post', 'tone' => 'info']);
});

it('INT-004 shows when the bridge last posted, and the weight and steps that post carried', function () {
    $page = bridgePage(bridgeToken(), [
        bridgeSteps('2026-11-02T06:02:05Z'),
        bridgeWeight('2026-11-02T06:02:01Z'),
    ]);

    expect($page['status'])->toBe(['text' => 'Last post today, 06:02', 'tone' => 'good'])
        ->and($page['last_post'])->toBe([
            'when_text' => 'Today, 06:02',
            'items' => [
                ['label' => 'Body weight', 'value' => '81.4 kg at 06:00'],
                ['label' => 'Steps', 'value' => '8,412 steps on 1 November'],
            ],
        ]);
});

it('INT-004 shows the weight in the unit the Shortcut sent it in, with its day when it is not the post\'s', function () {
    $page = bridgePage(bridgeToken(), [bridgeWeight('2026-11-02T06:02:00Z', 180, 'lb', '2026-10-31T07:15:00+00:00')]);

    expect($page['last_post']['items'])->toBe([['label' => 'Body weight', 'value' => '180.0 lb on 31 October at 07:15']]);
});

it('INT-004 waits for the first post from a new token, though an earlier token posted, and still shows that post', function () {
    $page = bridgePage(bridgeToken(['created_at' => CarbonImmutable::parse('2026-11-02T08:00:00Z')]), [bridgeSteps('2026-11-02T06:02:00Z')]);

    expect($page['status'])->toBe(['text' => 'Waiting for the first post', 'tone' => 'info'])
        ->and($page['last_post']['when_text'])->toBe('Today, 06:02');
});

it('INT-004 counts a post from yesterday as running, as the automation runs once a day', function () {
    $page = bridgePage(bridgeToken(), [bridgeSteps('2026-11-01T06:02:00Z')]);

    expect($page['status'])->toBe(['text' => 'Last post yesterday, 06:02', 'tone' => 'good'])
        ->and($page['last_post']['when_text'])->toBe('Yesterday, 06:02');
});

it('INT-004 warns when the bridge has not posted for two days or more, so the user can tell the automation stopped', function () {
    $page = bridgePage(bridgeToken(), [bridgeSteps('2026-10-30T06:01:00Z')]);

    expect($page['status'])->toBe(['text' => 'No posts for 3 days. Check the automation is still on.', 'tone' => 'warning'])
        ->and($page['last_post']['when_text'])->toBe('30 October, 06:01');
});

it('INT-004 warns from exactly two days without a post', function () {
    expect(bridgePage(bridgeToken(), [bridgeSteps('2026-10-31T06:01:00Z')])['status'])
        ->toBe(['text' => 'No posts for 2 days. Check the automation is still on.', 'tone' => 'warning']);
});

it('INT-004 shows only what the last run carried: an older post of the other kind is not part of it', function () {
    $page = bridgePage(bridgeToken(), [
        bridgeSteps('2026-11-02T06:02:00Z'),
        bridgeWeight('2026-11-01T06:02:00Z'),
    ]);

    expect(array_column($page['last_post']['items'], 'label'))->toBe(['Steps']);
});

it('INT-004 shows each kind once when a run posted it twice, the newest value', function () {
    $page = bridgePage(bridgeToken(), [
        bridgeSteps('2026-11-02T06:02:09Z', 9000),
        bridgeSteps('2026-11-02T06:02:05Z', 8412),
        bridgeWeight('2026-11-02T06:02:01Z'),
    ]);

    expect($page['last_post']['items'])->toBe([
        ['label' => 'Body weight', 'value' => '81.4 kg at 06:00'],
        ['label' => 'Steps', 'value' => '9,000 steps on 1 November'],
    ]);
});

it('INT-004 does not count a refused post as the bridge posting', function () {
    $page = bridgePage(bridgeToken(), [
        bridgePost('steps_log', ['date' => '2026-11-01', 'steps' => -1], '2026-11-02T06:02:00Z', ok: false),
        bridgeSteps('2026-10-30T06:01:00Z'),
    ]);

    expect($page['last_post']['when_text'])->toBe('30 October, 06:01');
});

it('INT-004 still shows the last post after the token is revoked, and that the bridge is not set up', function () {
    $page = bridgePage(null, [bridgeSteps('2026-11-02T06:02:00Z')]);

    expect($page['status']['text'])->toBe('Not set up')
        ->and($page['last_post']['when_text'])->toBe('Today, 06:02');
});

it('INT-004 dates a post from another year with its year', function () {
    expect(bridgePage(bridgeToken(), [bridgeSteps('2025-12-30T06:01:00Z', date: '2025-12-29')], '2026-01-02T09:00:00Z')['last_post'])
        ->toBe(['when_text' => '30 December 2025, 06:01', 'items' => [['label' => 'Steps', 'value' => '8,412 steps on 29 December 2025']]]);
});
