<?php

use App\Capabilities\WeighIns\WeighInsPayload;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/WeighIns/WeighInsModelTests.swift (help).

it('WT-005 says how to weigh in and that only the trend matters', function () {
    $help = strtolower(WeighInsPayload::HELP);

    expect($help)->toContain('first thing in the morning')
        ->toContain('bathroom')
        ->toContain('minimal clothing')
        ->toContain('single readings bounce around')
        ->toContain('only the trend matters')
        ->and(WeighInsPayload::HELP_TITLE)->not->toBe('');
});
