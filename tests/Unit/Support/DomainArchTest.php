<?php

use Tests\Support\DomainArch;

it('the domain date check catches every way of building a date', function (string $code) {
    expect(preg_match(DomainArch::BUILDS_DATE, $code))->toBe(1);
})->with([
    'new DateTimeImmutable()',
    'new \DateTimeImmutable',
    'new DateTime("today")',
    'new \DatePeriod($a, $b, $c)',
    'DateTimeImmutable::createFromFormat("Y", "2026")',
    '\DateTime::createFromInterface($d)',
]);

it('the domain date check allows time zones and moving a date it was given', function (string $code) {
    expect(preg_match(DomainArch::BUILDS_DATE, $code))->toBe(0);
})->with([
    "new DateTimeZone('UTC')",
    'DateTimeImmutable $today',
    "\$today->modify('-1 day')",
    'DateTimeInterface::ATOM',
]);

it('the domain date check refuses a folder that does not exist', function () {
    DomainArch::filesThatBuildDates('NoSuchFolder');
})->throws(RuntimeException::class);

it('the domain date check reads subfolders and names files relative to the folder', function () {
    $root = sys_get_temp_dir().'/domain-arch-'.bin2hex(random_bytes(4));
    mkdir("{$root}/Sub/Deeper", recursive: true);
    file_put_contents("{$root}/Top.php", "<?php\nreturn new DateTimeZone('UTC');\n");
    file_put_contents("{$root}/Sub/Deeper/Week.php", "<?php\nreturn new DateTimeImmutable('today');\n");
    file_put_contents("{$root}/Sub/Notes.txt", 'new DateTime()');
    try {
        expect(DomainArch::filesThatBuildDatesIn($root))->toBe(['Sub/Deeper/Week.php']);
    } finally {
        unlink("{$root}/Sub/Deeper/Week.php");
        unlink("{$root}/Sub/Notes.txt");
        unlink("{$root}/Top.php");
        rmdir("{$root}/Sub/Deeper");
        rmdir("{$root}/Sub");
        rmdir($root);
    }
});

it('the domain date check refuses a folder with no PHP files', function () {
    $root = sys_get_temp_dir().'/domain-arch-'.bin2hex(random_bytes(4));
    mkdir($root);
    try {
        DomainArch::filesThatBuildDatesIn($root);
    } finally {
        rmdir($root);
    }
})->throws(RuntimeException::class);
