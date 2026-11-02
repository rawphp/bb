<?php

use App\Domain\Billing\CurrentSubscription;

function sub(string $id, string $status, int $created): array
{
    return ['id' => $id, 'status' => $status, 'created' => $created];
}

it('SUB-021 a customer with no subscription has none to apply', function () {
    expect(CurrentSubscription::pick([]))->toBeNull();
});

it('SUB-010 a live subscription decides access over an ended one, whichever is newer', function () {
    $live = sub('sub_live', 'active', 100);
    $ended = sub('sub_ended', 'canceled', 200);

    expect(CurrentSubscription::pick([$ended, $live]))->toBe($live);
});

it('SUB-001 a paid subscription decides access over a trial', function () {
    $paid = sub('sub_paid', 'active', 100);

    expect(CurrentSubscription::pick([sub('sub_trial', 'trialing', 200), $paid]))->toBe($paid);
});

it('SUB-003 a trial decides access over a first payment still being taken', function () {
    $trial = sub('sub_trial', 'trialing', 100);

    expect(CurrentSubscription::pick([sub('sub_new', 'incomplete', 200), $trial]))->toBe($trial);
});

it('SUB-010 among subscriptions alike, the newest decides', function () {
    $newer = sub('sub_2', 'canceled', 200);

    expect(CurrentSubscription::pick([sub('sub_1', 'canceled', 100), $newer]))->toBe($newer);
});

it('SUB-010 an ended subscription decides over a new one whose first payment has not gone through', function () {
    $ended = sub('sub_old', 'canceled', 100);

    expect(CurrentSubscription::pick([sub('sub_new', 'incomplete', 200), $ended]))->toBe($ended);
});

it('SUB-003 a trial decides over an ended subscription, even a newer one', function () {
    $trial = sub('sub_trial', 'trialing', 100);

    expect(CurrentSubscription::pick([sub('sub_old', 'canceled', 200), $trial]))->toBe($trial);
});
