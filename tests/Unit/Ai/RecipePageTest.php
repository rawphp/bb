<?php

use App\Ai\PageUnavailable;
use App\Ai\RecipePage;
use Illuminate\Support\Facades\Http;

function recipePage(array $ips = ['93.184.216.34']): RecipePage
{
    return new RecipePage(fn (string $host) => $ips);
}

beforeEach(fn () => Http::preventStrayRequests());

it('RCP-003 rejects hosts that resolve to private or reserved addresses', function (string $ip) {
    Http::fake();

    expect(fn () => recipePage([$ip])->text('http://recipes.example/pie'))->toThrow(PageUnavailable::class);
    Http::assertNothingSent();
})->with(['127.0.0.1', '10.1.2.3', '169.254.169.254', '192.168.0.9', '::1', '100.64.0.1', '198.18.0.1', '64:ff9b::7f00:1', '2002:7f00:1::', 'fc00::1', '::ffff:127.0.0.1', '::ffff:a9fe:a9fe', '0.0.0.0', '::', '64:ff9b:1::7f00:1', 'fe80::1', '172.16.0.1']);

it('RCP-003 rejects a host when any resolved address is private', function () {
    Http::fake();

    expect(fn () => recipePage(['93.184.216.34', '10.0.0.1'])->text('http://recipes.example/pie'))->toThrow(PageUnavailable::class);
});

it('RCP-003 rejects IP-literal private hosts and unresolvable hosts', function (string $url, array $ips) {
    Http::fake();

    expect(fn () => recipePage($ips)->text($url))->toThrow(PageUnavailable::class);
    Http::assertNothingSent();
})->with([
    ['http://127.0.0.1/x', ['93.184.216.34']],
    ['http://[::1]/x', ['93.184.216.34']],
    ['http://169.254.169.254/latest/meta-data', ['93.184.216.34']],
    ['http://nowhere.example/x', []],
]);

it('RCP-003 rejects schemes other than http and https', function (string $url) {
    Http::fake();

    expect(fn () => recipePage()->text($url))->toThrow(PageUnavailable::class);
    Http::assertNothingSent();
})->with(['ftp://recipes.example/pie', 'file:///etc/passwd', 'gopher://recipes.example/']);

it('RCP-003 rejects a redirect to a private host', function () {
    Http::fake([
        'recipes.example/*' => Http::response('', 302, ['Location' => 'http://internal.example/admin']),
        'internal.example/*' => Http::response('<p>secret</p>'),
    ]);
    $page = new RecipePage(fn (string $host) => $host === 'internal.example' ? ['10.0.0.5'] : ['93.184.216.34']);

    expect(fn () => $page->text('http://recipes.example/pie'))->toThrow(PageUnavailable::class);
    Http::assertSentCount(1);
});

it('RCP-003 follows relative redirects', function () {
    Http::fake([
        'recipes.example/a' => Http::response('', 301, ['Location' => '/b']),
        'recipes.example/b' => Http::response('<p>Final</p>'),
        'recipes.example/dir/' => Http::response('', 302, ['Location' => 'page']),
        'recipes.example/dir/page' => Http::response('<p>In dir</p>'),
    ]);

    expect(recipePage()->text('http://recipes.example/a'))->toBe('Final')
        ->and(recipePage()->text('http://recipes.example/dir/'))->toBe('In dir');
});

it('RCP-003 gives up after 3 redirects', function () {
    Http::fake(['recipes.example/loop' => Http::response('', 302, ['Location' => '/loop'])]);

    expect(fn () => recipePage()->text('http://recipes.example/loop'))->toThrow(PageUnavailable::class);
    Http::assertSentCount(4);
});

it('RCP-003 rejects non-2xx responses and oversized bodies', function () {
    Http::fake([
        'recipes.example/missing' => Http::response('nope', 404),
        'recipes.example/huge' => Http::response(str_repeat('a', 2 * 1024 * 1024 + 1)),
    ]);

    expect(fn () => recipePage()->text('http://recipes.example/missing'))->toThrow(PageUnavailable::class)
        ->and(fn () => recipePage()->text('http://recipes.example/huge'))->toThrow(PageUnavailable::class);
});

it('RCP-003 pins the connection to the checked address and caps the download while it runs', function () {
    $options = null;
    Http::fake(function ($request, $opts) use (&$options) {
        $options = $opts;

        return Http::response('<p>ok</p>');
    });

    expect(recipePage()->text('http://recipes.example/pie'))->toBe('ok')
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['recipes.example:80:93.184.216.34'])
        // The stream handler refuses curl options, so the pin needs the curl handler: no streaming.
        ->and($options['stream'] ?? false)->toBeFalse()
        // Raw bytes are counted, so a compressed page cannot grow past the cap once decoded.
        ->and($options['decode_content'])->toBeFalse()
        ->and($options['progress'](0, 1000, 0, 0))->toBeFalse()
        ->and($options['progress'](0, 2 * 1024 * 1024 + 1, 0, 0))->toBeTrue()
        ->and($options['progress'](3 * 1024 * 1024, 0, 0, 0))->toBeTrue();
});

it('RCP-003 pins a named host on its own port and leaves an IP literal unpinned', function () {
    $sent = [];
    Http::fake(function ($request, $opts) use (&$sent) {
        $sent[] = $opts['curl'] ?? null;

        return Http::response('<p>ok</p>');
    });

    recipePage()->text('https://recipes.example:8443/pie');
    recipePage()->text('https://recipes.example/pie');
    recipePage()->text('https://93.184.216.34/pie');

    expect($sent)->toBe([
        [CURLOPT_RESOLVE => ['recipes.example:8443:93.184.216.34']],
        [CURLOPT_RESOLVE => ['recipes.example:443:93.184.216.34']],
        null,
    ]);
});

it('RCP-003 pins every redirect hop to the address checked for its own host', function () {
    $sent = [];
    Http::fake(function ($request, $opts) use (&$sent) {
        $sent[] = $opts['curl'][CURLOPT_RESOLVE] ?? null;

        return $request->url() === 'http://a.example/pie'
            ? Http::response('', 302, ['Location' => 'https://b.example/pie'])
            : Http::response('<p>Pie</p>');
    });
    $page = new RecipePage(fn (string $host) => $host === 'a.example' ? ['93.184.216.34'] : ['93.184.216.35']);

    expect($page->text('http://a.example/pie'))->toBe('Pie')
        ->and($sent)->toBe([['a.example:80:93.184.216.34'], ['b.example:443:93.184.216.35']]);
});

it('RCP-003 accepts a body just under the size cap', function () {
    Http::fake(['recipes.example/*' => Http::response('<p>'.str_repeat('a', 2 * 1024 * 1024 - 7).'</p>')]);

    // the page text itself is truncated to 100000 characters after extraction
    expect(strlen(recipePage()->text('http://recipes.example/big')))->toBe(100000);
});

it('RCP-003 returns the Recipe JSON-LD found inside @graph', function () {
    $ld = json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'WebSite', 'name' => 'Site'],
        ['@type' => ['Recipe'], 'name' => 'Pancakes', 'recipeIngredient' => ['2 eggs', '200 g flour']],
    ]]);
    Http::fake(['recipes.example/*' => Http::response("<html><body><p>Chatter</p><script type=\"application/ld+json\">{$ld}</script></body></html>")]);

    $text = recipePage()->text('http://recipes.example/pancakes');

    expect(json_decode($text, true))->toBe(['@type' => ['Recipe'], 'name' => 'Pancakes', 'recipeIngredient' => ['2 eggs', '200 g flour']])
        ->and($text)->not->toContain('Chatter');
});

it('RCP-003 finds a Recipe in a top-level JSON-LD list', function () {
    $ld = json_encode([['@type' => 'Organization'], ['@type' => 'Recipe', 'name' => 'Soup']]);
    Http::fake(['recipes.example/*' => Http::response("<script type='application/ld+json'>{$ld}</script>")]);

    expect(json_decode(recipePage()->text('http://recipes.example/soup'), true))->toBe(['@type' => 'Recipe', 'name' => 'Soup']);
});

it('RCP-003 falls back to stripped page text', function () {
    Http::fake(['recipes.example/*' => Http::response(
        '<html><head><style>p{color:red}</style></head><body><script>var x = 1;</script>'
        ."<h1>Big   Soup</h1>\n<p>1 onion &amp; 2 carrots</p><script type=\"application/ld+json\">{\"@type\":\"Organization\"}</script></body></html>"
    )]);

    $text = recipePage()->text('http://recipes.example/soup');

    expect($text)->toContain('Big Soup 1 onion & 2 carrots')
        ->and($text)->not->toContain('color:red')->not->toContain('var x')->not->toContain('<');
});

it('RCP-003 fetches a host that resolves to a public IPv6 address, pinned to it', function () {
    $sent = [];
    Http::fake(function ($request, $opts) use (&$sent) {
        $sent[] = $opts['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response('<p>Pie</p>');
    });

    expect(recipePage(['2606:4700::1111'])->text('https://recipes.example/pie'))->toBe('Pie')
        ->and($sent)->toBe([['recipes.example:443:2606:4700::1111']]);
});

it('RCP-003 fetches a public IPv6 literal URL and refuses a private one', function () {
    Http::fake(['*' => Http::response('<p>Pie</p>')]);

    expect(recipePage()->text('https://[2606:4700::1111]/pie'))->toBe('Pie')
        ->and(fn () => recipePage()->text('http://[::ffff:127.0.0.1]/pie'))->toThrow(PageUnavailable::class);
    Http::assertSentCount(1);
});
