<?php

use Illuminate\Filesystem\Filesystem;
use Tests\Support\CapabilityParity;

it('CAP-030 finds each capability name passed to read( or write( in app source', function () {
    $source = <<<'TS'
        const a = await read<AccountShowOutput>('account_show', {}, onUpdate);
        await write("meal_add", { ulid });
        await read(
            'today_show',
            { date },
        );
        const b = await read<Map<string, number>>(`week_show`);
        fetchRead('not_a_call');
        cache.read('not_a_call_either');
        read(name, input);
        TS;

    expect(CapabilityParity::calledIn($source))->toBe(['account_show', 'meal_add', 'today_show', 'week_show']);
});

it('CAP-030 scans .ts and .vue app files at any depth and leaves out test and spec files', function () {
    $root = sys_get_temp_dir().'/parity-'.bin2hex(random_bytes(6));
    $files = [
        'app/call.ts' => "write('from_ts')",
        'features/a/AView.vue' => "read('from_vue'); read('from_both')",
        'design/B.vue' => "write('from_both')",
        'app/call.test.ts' => "read('from_test')",
        'features/a/AView.spec.ts' => "read('from_spec')",
        'notes.md' => "read('from_markdown')",
    ];
    foreach ($files as $path => $source) {
        @mkdir(dirname("{$root}/{$path}"), recursive: true);
        file_put_contents("{$root}/{$path}", $source);
    }

    try {
        expect(CapabilityParity::calledInApp($root))->toBe([
            'from_both' => ['design/B.vue', 'features/a/AView.vue'],
            'from_ts' => ['app/call.ts'],
            'from_vue' => ['features/a/AView.vue'],
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

$excluded = fn (string $name): ?string => $name === 'account_delete' ? 'A person must do it.' : null;

it('CAP-030 passes a UI name that is registered and an agent tool', function () use ($excluded) {
    expect(CapabilityParity::problems(
        ui: ['account_show' => ['a.vue']],
        registered: ['account_show'],
        tools: ['account_show'],
        snapshots: ['account_show'],
        owners: [],
        excluded: $excluded,
    ))->toBe([]);
});

it('CAP-030 passes a UI name not registered yet whose schema snapshot and owner issue wait for it', function () use ($excluded) {
    expect(CapabilityParity::problems(['meal_add' => ['a.vue']], [], [], ['meal_add'], ['meal_add' => 68], $excluded))->toBe([]);
});

it('CAP-030 fails a UI name that is neither registered nor in a schema snapshot', function () use ($excluded) {
    expect(CapabilityParity::problems(['meal_ad' => ['a.vue']], [], [], ['meal_add'], ['meal_ad' => 68], $excluded))
        ->toBe(['a.vue calls meal_ad, which is not registered and has no schema snapshot in tests/fixtures/schemas/']);
});

it('CAP-030 fails a UI name with a schema snapshot but no issue that will register it', function () use ($excluded) {
    expect(CapabilityParity::problems(['meal_add' => ['a.vue']], [], [], ['meal_add'], [], $excluded))
        ->toBe(['a.vue calls meal_add, which is not registered and no owners file names the issue that registers it']);
});

it('CAP-030 reads each snapshot name once from the schema fixtures', function () {
    $dir = base_path('tests/fixtures/schemas');

    expect(CapabilityParity::snapshotNames($dir))->toContain('account_show', 'meal_add')
        ->and(CapabilityParity::snapshotNames($dir))->not->toContain('today.owners')
        ->and(CapabilityParity::snapshotNames($dir))->toBe(array_values(array_unique(CapabilityParity::snapshotNames($dir))));
});

it('AGT-001 fails a registered capability the agent cannot call', function () use ($excluded) {
    expect(CapabilityParity::problems(['account_show' => ['a.vue']], ['account_show', 'ops_show'], [], ['account_show'], [], $excluded))
        ->toBe([
            'account_show is registered but not an agent tool, and not on the CAP-031 exclusion list',
            'ops_show is registered but not an agent tool, and not on the CAP-031 exclusion list',
        ]);
});

it('CAP-031 passes a registered capability kept from the agent by the exclusion list', function () use ($excluded) {
    expect(CapabilityParity::problems(['account_delete' => ['a.vue']], ['account_delete'], [], ['account_delete'], [], $excluded))->toBe([]);
});

// RegistryToolCatalog drops exclusions today; this guards the catalog against losing that.
it('CAP-031 fails an excluded capability that is still an agent tool', function () use ($excluded) {
    expect(CapabilityParity::problems([], ['account_delete'], ['account_delete'], [], [], $excluded))
        ->toBe(['account_delete is on the CAP-031 exclusion list but is still an agent tool']);
});
