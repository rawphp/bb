<?php

use App\Ai\RecipePage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * RecipePage's transfer options through the real curl handler, against a local server: Http::fake never
 * reaches Guzzle's handler, so only a real response shows the download cap aborts the transfer.
 * The address check refuses loopback, so this drives the options, not text().
 */

beforeAll(function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($probe, false);
    fclose($probe);

    $GLOBALS['recipePageServer'] = [
        'url' => "http://{$address}/",
        'process' => proc_open(
            [PHP_BINARY, '-S', $address, dirname(__DIR__, 2).'/Support/Fixtures/Ai/recipe-page-server.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        ),
    ];

    for ($try = 0; $try < 50 && ($socket = @fsockopen('tcp://'.$address)) === false; $try++) {
        usleep(100_000);
    }
    if ($socket !== false) {
        fclose($socket);
    }
});

afterAll(function () {
    proc_terminate($GLOBALS['recipePageServer']['process']);
    proc_close($GLOBALS['recipePageServer']['process']);
});

function recipePageGet(string $query): string
{
    return Http::withOptions(RecipePage::transferOptions())->timeout(10)->get($GLOBALS['recipePageServer']['url'].'?'.$query)->body();
}

it('RCP-003 aborts the download of a page over 2 MB that says its size', function () {
    expect(fn () => recipePageGet('bytes='.(10 * 1024 * 1024)))->toThrow(ConnectionException::class, 'aborted by the progress callback');
});

it('RCP-003 aborts the download of a page over 2 MB that does not say its size', function () {
    expect(fn () => recipePageGet('chunked=1&bytes='.(10 * 1024 * 1024)))->toThrow(ConnectionException::class, 'aborted by the progress callback');
});

it('RCP-003 downloads a page just under 2 MB', function () {
    expect(strlen(recipePageGet('chunked=1&bytes='.(2 * 1024 * 1024))))->toBe(2 * 1024 * 1024);
});
