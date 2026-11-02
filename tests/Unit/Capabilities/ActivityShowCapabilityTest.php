<?php

use App\Capabilities\Reads\ActivityShowCapability;
use App\Models\AuditRecord;
use Carbon\CarbonImmutable;

function auditRecord(array $attributes): AuditRecord
{
    $record = new AuditRecord;
    $record->forceFill($attributes + [
        'id' => '01JBXK3V8Q2M5T7W9Y1A3C5E7G',
        'capability' => 'weigh_in_log',
        'channel' => 'ui',
        'result' => ['ok' => true, 'summary' => ['invalidates' => [], 'toast' => null, 'undo' => null]],
        'created_at' => CarbonImmutable::parse('2026-11-02T18:14:00Z'),
    ]);

    return $record;
}

$now = CarbonImmutable::parse('2026-11-02T20:00:00Z');

it('CAP-022 titles a write with its toast and shows the channel in words', function () use ($now) {
    $entry = ActivityShowCapability::entry(auditRecord([
        'channel' => 'bridge',
        'result' => ['ok' => true, 'summary' => ['invalidates' => [], 'toast' => 'Logged weigh-in, 81.4 kg', 'undo' => null]],
    ]), $now);

    expect($entry->toArray())->toBe([
        'id' => '01JBXK3V8Q2M5T7W9Y1A3C5E7G',
        'title' => 'Logged weigh-in, 81.4 kg',
        'channel' => 'bridge',
        'channel_label' => 'Health bridge',
        'occurred_at' => '2026-11-02T18:14:00+00:00',
        'when_text' => 'Today, 18:14',
        'result' => ['text' => 'Done', 'tone' => 'good'],
    ]);
});

it('CAP-022 titles a write without a toast with its capability name in words', function () use ($now) {
    expect(ActivityShowCapability::entry(auditRecord([]), $now)->title)->toBe('Weigh in log');
});

it('CAP-022 shows a refused write with the server\'s plain message', function () use ($now) {
    $entry = ActivityShowCapability::entry(auditRecord([
        'result' => ['ok' => false, 'code' => 'conflict', 'message' => 'This workout was already finished.'],
    ]), $now);

    expect($entry->result->toArray())->toBe(['text' => 'Refused: this workout was already finished', 'tone' => 'warning'])
        ->and($entry->title)->toBe('Weigh in log');
});

it('CAP-012 words a package refusal plainly in Activity too', function () use ($now) {
    $entry = ActivityShowCapability::entry(auditRecord([
        'result' => ['ok' => false, 'code' => 'forbidden', 'message' => 'Not authorized to invoke "weigh_in_log".'],
    ]), $now);

    expect($entry->result->text)->toBe('Refused: you can\'t do that');
});

it('CAP-022 says when a write happened relative to today', function (string $at, string $text) use ($now) {
    expect(ActivityShowCapability::entry(auditRecord(['created_at' => CarbonImmutable::parse($at)]), $now)->when_text)->toBe($text);
})->with([
    'today' => ['2026-11-02T00:05:00Z', 'Today, 00:05'],
    'yesterday' => ['2026-11-01T21:30:00Z', 'Yesterday, 21:30'],
    'this year' => ['2026-10-26T09:10:00Z', 'Mon 26 Oct, 09:10'],
    'last year' => ['2025-12-30T09:10:00Z', '30 Dec 2025, 09:10'],
]);

it('CAP-022 shows a write whose reply failed the output schema as done, with a server error, not refused', function () use ($now) {
    $entry = ActivityShowCapability::entry(auditRecord([
        'event' => 'capability.output_invalid',
        'result' => ['ok' => false, 'code' => 'output_invalid', 'message' => 'Output failed validation.'],
    ]), $now);

    expect($entry->title)->toBe('Weigh in log')
        ->and($entry->result->toArray())->toBe(['text' => 'Done, with a server error', 'tone' => 'warning']);
});

it('CAP-022 still names an entry with no capability or no result', function () use ($now) {
    $entry = ActivityShowCapability::entry(auditRecord(['capability' => null, 'result' => null]), $now);

    expect($entry->title)->toBe('Change')
        ->and($entry->result->toArray())->toBe(['text' => 'No result recorded', 'tone' => 'neutral']);
});
