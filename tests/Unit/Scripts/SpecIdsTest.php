<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/spec-ids-'.uniqid();
    File::ensureDirectoryExists($this->dir.'/spec');
    File::put($this->dir.'/spec/01.md', "- **ABC-001** [B] A rule.\n\n## TBL-002 A table\n");
    File::ensureDirectoryExists($this->dir.'/tests');
});

afterEach(fn () => File::deleteDirectory($this->dir));

function specIds(string $dir): ProcessResult
{
    return Process::path(base_path())->run(['bash', 'scripts/spec-ids.sh', $dir.'/spec', $dir.'/tests']);
}

it('passes when every ID a test names is in the spec', function () {
    File::put($this->dir.'/tests/ATest.php', "<?php\nit('ABC-001 does the thing', fn () => null);\ntest(\"TBL-002 row\", fn () => null);\n");

    expect(specIds($this->dir)->successful())->toBeTrue();
});

it('fails when a test names an ID that is not in the spec', function () {
    File::put($this->dir.'/tests/ATest.php', "<?php\nit('ABC-001 and ABC-009 do the thing', fn () => null);\n");

    $result = specIds($this->dir);

    expect($result->failed())->toBeTrue()
        ->and($result->output())->toContain('ABC-009')->not->toContain('ABC-001:');
});
