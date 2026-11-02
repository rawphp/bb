<?php

use App\Domain\Account\EmailAddress;

it('ACC-001 normalises an email to the trimmed lower-case form accounts store', function () {
    expect(EmailAddress::normalise('  Sam@Example.COM '))->toBe('sam@example.com');
});
