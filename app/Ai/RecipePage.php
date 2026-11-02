<?php

namespace App\Ai;

use Closure;
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

    /**
     * The options every hop is fetched with, before its pin. Not streamed: the pin is a curl option,
     * and Guzzle sends a streamed request through its stream handler, which refuses curl options. The
     * progress callback caps the download instead: curl aborts the transfer once the page says, or
     * turns out, to be over MAX_BYTES. It counts raw bytes, since the body is not decoded, so a
     * compressed page cannot grow past the cap.
     *
     * @return array<string, mixed>
     */
    public static function transferOptions(): array
    {
        return [
            'allow_redirects' => false,
            'decode_content' => false,
            'progress' => fn (int $total, int $downloaded): bool => $total > self::MAX_BYTES || $downloaded > self::MAX_BYTES,
        ];
    }

    /** @throws PageUnavailable */
    public function text(string $url): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $options = self::transferOptions();
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
            if (! $response->successful() || strlen($response->body()) > self::MAX_BYTES) {
                throw new PageUnavailable('Page could not be fetched.');
            }

            return $this->extract($response->body());
        }

        throw new PageUnavailable('Too many redirects.');
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
            if (! self::isPublic($ip)) {
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

        $path = $parts['path'] ?? '/';
        $directory = str_ends_with($path, '/') ? $path : dirname($path);

        return $origin.rtrim($directory, '/').'/'.$location;
    }

    /**
     * Only globally reachable addresses (RFC 6890): no private, loopback, link-local, shared (CGNAT),
     * benchmark or reserved range. NAT64 (64:ff9b::/96, 64:ff9b:1::/48) is refused too, since it
     * reaches whatever IPv4 address it embeds.
     */
    private static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($ip);

        return ! (strlen((string) $packed) === 16 && str_starts_with(bin2hex((string) $packed), '0064ff9b'));
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
