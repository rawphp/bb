<?php

use App\Capabilities\Undo\Data\UndoApplyInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// undo_apply's contract waits here for the UNDO issue (#10), which registers it.
// An undo token is the id of the write's undo row, a ULID, so the input's rules()
// refuse anything else. The schema snapshot still says only "string": the package
// writes no format for the server-side `ulid` rule, as for every CAP-019 ULID.

it('CAP-013 takes an undo token that is a ULID', function () {
    expect(Validator::make(['token' => (string) Str::ulid()], UndoApplyInput::rules())->passes())->toBeTrue();
});

it('CAP-013 refuses an undo token that is not a ULID, such as the Toast gallery\'s made-up ones', function () {
    expect(Validator::make(['token' => 'gallery-default'], UndoApplyInput::rules())->errors()->keys())->toBe(['token']);
});

it('CAP-013 snapshots undo_apply\'s token as a required string', function () {
    $schema = json_decode((string) file_get_contents(base_path('tests/fixtures/schemas/undo_apply.input.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($schema['properties'])->toBe(['token' => ['type' => 'string']])
        ->and($schema['required'])->toBe(['token']);
});
