<?php

use App\Ai\PageUnavailable;
use App\Ai\RecipePage;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
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
})->with(['127.0.0.1', '10.1.2.3', '169.254.169.254', '192.168.0.9', '::1']);

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

it('RCP-003 follows relative redirects and gives up after 3', function () {
    Http::fake([
        'recipes.example/a' => Http::response('', 301, ['Location' => '/b']),
        'recipes.example/b' => Http::response('<p>Final</p>'),
        'recipes.example/loop' => Http::response('', 302, ['Location' => '/loop']),
    ]);

    expect(recipePage()->text('http://recipes.example/a'))->toBe('Final')
        ->and(fn () => recipePage()->text('http://recipes.example/loop'))->toThrow(PageUnavailable::class);
});

it('RCP-003 rejects non-2xx responses and oversized bodies', function () {
    Http::fake([
        'recipes.example/missing' => Http::response('nope', 404),
        'recipes.example/huge' => Http::response(str_repeat('a', 2 * 1024 * 1024 + 1)),
    ]);

    expect(fn () => recipePage()->text('http://recipes.example/missing'))->toThrow(PageUnavailable::class)
        ->and(fn () => recipePage()->text('http://recipes.example/huge'))->toThrow(PageUnavailable::class);
});

it('RCP-003 enforces the size cap while streaming, not after buffering the body', function () {
    $read = 0;
    $options = null;
    $body = new PumpStream(function (int $length) use (&$read) {
        if ($read >= 50 * 1024 * 1024) {
            return false;
        }
        $read += $length;

        return str_repeat('a', $length);
    });
    Http::fake(function ($request, $opts) use ($body, &$options) {
        $options = $opts;

        return new PsrResponse(200, [], $body);
    });

    expect(fn () => recipePage()->text('http://recipes.example/bomb'))->toThrow(PageUnavailable::class)
        ->and($options['stream'])->toBeTrue()
        ->and($read)->toBeLessThan(2 * 1024 * 1024 + 64 * 1024);
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
