<?php

use App\Capabilities\Support\Tone;

it('CAP-018 offers exactly the four UI-004 tones', function () {
    expect(array_map(fn (Tone $tone) => $tone->value, Tone::cases()))
        ->toBe(['good', 'neutral', 'warning', 'info']);
});

it('CAP-018 sends a tone as its name', function () {
    expect(json_encode(['tone' => Tone::Warning]))->toBe('{"tone":"warning"}');
});
