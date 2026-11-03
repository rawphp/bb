<?php

use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;

it('CAP-018 lets a status carry only the tones the server picks from', function () {
    expect(Status::jsonSchema()['properties']['tone']['enum'])
        ->toBe(array_column(Tone::cases(), 'value'));
});
