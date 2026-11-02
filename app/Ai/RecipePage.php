<?php

namespace App\Ai;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * RCP-003: fetch a user-supplied URL without letting it reach internal addresses. Every host is
 * resolved and checked, the connection is pinned to the checked address, and redirects are
 * followed by hand so each hop is checked too.
 */
final class RecipePage
{
    private const MAX_REDIRECTS = 3;

    private const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_TEXT = 100000;

    /** @var Closure(string): list<string> */
    private Closure $resolve;

    /** @param  (Closure(string): list<string>)|null  $resolve  host to IPs; injectable so tests never hit DNS */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? fn (string $host) => gethostbynamel($host) ?: [];
    }

    /** @throws PageUnavailable */
    public function text(string $url): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $options = ['allow_redirects' => false, 'stream' => true];
            $pin = $this->check($url);
            if ($pin !== null) {
                $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
            }

            try {
                $response = Http::withOptions($options)->timeout(10)->get($url);
            } catch (Throwable) {
                throw new PageUnavailable('Page could not be fetched.');
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true) && $response->header('Location') !== '') {
                $url = $this->absolute($url, $response->header('Location'));

                continue;
            }
            if (! $response->successful()) {
                throw new PageUnavailable('Page could not be fetched.');
            }

            return $this->extract($this->read($response));
        }

        throw new PageUnavailable('Too many redirects.');
    }

    /**
     * Read the body in chunks and give up as soon as it passes MAX_BYTES, so a huge or
     * compressed-bomb page is never fully buffered (decoded bytes count).
     *
     * @throws PageUnavailable
     */
    private function read(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        try {
            while (! $stream->eof()) {
                $body .= $stream->read(8192);
                if (strlen($body) > self::MAX_BYTES) {
                    throw new PageUnavailable('Page could not be fetched.');
                }
            }
        } catch (PageUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            throw new PageUnavailable('Page could not be fetched.');
        } finally {
            $stream->close();
        }

        return $body;
    }

    /**
     * Refuse non-public targets. Returns the curl pin ("host:port:ip") for named hosts, null for IP literals.
     *
     * @throws PageUnavailable
     */
    private function check(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim($parts['host'] ?? '', '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new PageUnavailable('Unsupported URL.');
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $ips = $literal ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            throw new PageUnavailable('Host did not resolve.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new PageUnavailable('Host is not public.');
            }
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $literal ? null : "{$host}:{$port}:{$ips[0]}";
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }

    /** The Recipe JSON-LD when the page has one, else its visible text. */
    private function extract(string $html): string
    {
        preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks);
        foreach ($blocks[1] as $json) {
            $recipe = $this->findRecipe(json_decode(html_entity_decode(trim($json)), true));
            if ($recipe !== null) {
                return mb_substr(json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, self::MAX_TEXT);
            }
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_TEXT);
    }

    /** @return array<string, mixed>|null */
    private function findRecipe(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        if (in_array('Recipe', (array) ($node['@type'] ?? []), true)) {
            return $node;
        }
        foreach ($node['@graph'] ?? $node as $child) {
            if (($found = $this->findRecipe($child)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
