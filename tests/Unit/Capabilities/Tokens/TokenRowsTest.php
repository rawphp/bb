<?php

use App\Capabilities\Tokens\TokenRows;
use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-02 09:30:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function tokenRow(array $attributes): array
{
    $token = new PersonalAccessToken(['id' => '01JBQ2W8E4R6T8Y0U2I4O6P8A0', 'name' => 'Laptop MCP', 'scope' => TokenScope::ReadOnly, ...$attributes]);
    $token->created_at = $attributes['created_at'] ?? '2026-09-01 10:00:00';
    $token->last_used_at = $attributes['last_used_at'] ?? null;

    return TokenRows::row($token)->toArray();
}

it('CAP-021 lists a token with its scope, dates and last use in words', function () {
    expect(tokenRow(['expires_at' => '2027-11-02 09:00:00', 'last_used_at' => '2026-11-02 06:02:00']))->toBe([
        'id' => '01JBQ2W8E4R6T8Y0U2I4O6P8A0',
        'name' => 'Laptop MCP',
        'scope_text' => 'Read only',
        'created_text' => 'Created 1 September 2026',
        'expires_text' => 'Expires 2 November 2027',
        'last_used_text' => 'Last used today, 06:02',
        'status' => ['text' => 'Active', 'tone' => 'good'],
    ]);
});

it('CAP-021 says when a token was last used on an earlier day, or never', function (?string $used, string $text) {
    expect(tokenRow(['expires_at' => '2027-01-01', 'last_used_at' => $used])['last_used_text'])->toBe($text);
})->with([
    [null, 'Never used'],
    ['2026-10-05 21:00:00', 'Last used 5 October 2026'],
]);

it('CAP-018 warns in the week before a token expires, and marks an expired one', function (string $expires, array $status, string $text) {
    $row = tokenRow(['expires_at' => $expires]);

    expect($row['status'])->toBe($status)
        ->and($row['expires_text'])->toBe($text);
})->with([
    'in 8 days' => ['2026-11-10 08:00:00', ['text' => 'Active', 'tone' => 'good'], 'Expires 10 November 2026'],
    'in 3 days' => ['2026-11-05 08:00:00', ['text' => 'Expires in 3 days', 'tone' => 'warning'], 'Expires 5 November 2026'],
    'tomorrow' => ['2026-11-03 08:00:00', ['text' => 'Expires tomorrow', 'tone' => 'warning'], 'Expires 3 November 2026'],
    'today' => ['2026-11-02 18:00:00', ['text' => 'Expires today', 'tone' => 'warning'], 'Expires 2 November 2026'],
    'expired' => ['2026-11-01 08:00:00', ['text' => 'Expired', 'tone' => 'neutral'], 'Expired 1 November 2026'],
]);
