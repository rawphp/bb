<?php

use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityResult;

it('CAP-004 account_show answers the same on http, job, mcp and cli', function () {
    $user = new User(['name' => 'Sam Lifter', 'email' => 'sam@example.com']);

    app(CapabilityRegistry::class)->assertParity('account_show', [
        'surfaces' => ['http', 'job', 'mcp', 'cli'],
        'actor' => $user,
        'assert' => fn (CapabilityResult $result) => expect($result->data->toArray())
            ->toBe(['name' => 'Sam Lifter', 'email' => 'sam@example.com']),
    ]);
});
