<?php

namespace App\Actions\Settings;

use App\Capabilities\Reads\Data\HealthBridgeShowOutput;
use App\Capabilities\Reads\Data\Settings\BridgePost;
use App\Capabilities\Reads\Data\Settings\BridgeToken;
use App\Capabilities\Reads\Data\Settings\LabelValue;
use App\Capabilities\Reads\Data\Settings\TextLine;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Channel;
use App\Capabilities\Support\Tone;
use App\Capabilities\Tokens\TokenRows;
use App\Domain\Tokens\TokenScope;
use App\Models\AuditRecord;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The Health bridge page (INT-001, INT-004): the user's newest Health bridge token, without its secret, and the
 * bridge's last post, read from the audit log, where every write it makes is recorded under the `bridge` channel.
 *
 * A post is one run of the Shortcut: its weigh-in and steps writes, seconds apart. The run is the newest successful
 * bridge write and those made in the RUN_MINUTES before it, so an older post of the other kind is never shown as
 * part of it.
 */
final class ShowHealthBridge
{
    public const SCOPE_TEXT = 'The token can log weigh-ins and steps. It cannot read anything or change anything else.';

    /** How far apart one run's writes can be. */
    public const RUN_MINUTES = 10;

    /** The automation runs once a day, so a post this many days old or more means it stopped. */
    public const QUIET_DAYS = 2;

    /** The newest bridge writes read: enough for a run that retried a few times. */
    private const POSTS = 20;

    private const LABELS = ['weigh_in_log' => 'Body weight', 'steps_log' => 'Steps'];

    public function __invoke(User $user, CarbonImmutable $now): HealthBridgeShowOutput
    {
        $token = PersonalAccessToken::ownedBy($user)
            ->where('scope', TokenScope::HealthBridge->value)
            ->latest()
            ->orderByDesc('id')
            ->first();

        $posts = AuditRecord::query()
            ->whereBelongsTo($user)
            ->where('channel', Channel::Bridge->value)
            ->where('event', 'capability.invoked')
            ->whereIn('capability', TokenScope::HEALTH_BRIDGE)
            ->latest()
            ->latest('id')
            ->limit(self::POSTS)
            ->get(['capability', 'input', 'result', 'created_at'])
            ->all();

        return self::page($token, $posts, $now, (string) config('services.health_bridge.shortcut_url'), url('/capabilities'));
    }

    /**
     * @param  list<AuditRecord>  $posts  the user's newest bridge writes, newest first
     * @param  string  $endpoint  this server's capability API, which the Shortcut posts to
     */
    public static function page(?PersonalAccessToken $token, array $posts, CarbonImmutable $now, string $shortcutUrl, string $endpoint): HealthBridgeShowOutput
    {
        $run = self::lastRun($posts);
        $postedAt = $run === [] ? null : CarbonImmutable::instance($run[0]->created_at)->setTimezone($now->getTimezone());

        return new HealthBridgeShowOutput(
            status: self::status($token, $postedAt, $now),
            scope_text: self::SCOPE_TEXT,
            token: $token === null ? null : new BridgeToken(
                id: $token->id,
                name: $token->name,
                expires_text: TokenRows::expiresText($token),
                last_used_text: TokenRows::lastUsedText($token->last_used_at),
            ),
            last_post: $postedAt === null ? null : new BridgePost(
                when_text: ucfirst(self::when($postedAt, $now)),
                items: self::items($run, $postedAt, $now),
            ),
            shortcut_url: $shortcutUrl,
            steps: self::steps($endpoint),
        );
    }

    /**
     * The newest run's writes, newest first.
     *
     * @param  list<AuditRecord>  $posts
     * @return list<AuditRecord>
     */
    private static function lastRun(array $posts): array
    {
        $done = array_values(array_filter($posts, fn (AuditRecord $post): bool => ($post->result['ok'] ?? false) === true));
        if ($done === []) {
            return [];
        }

        $start = CarbonImmutable::instance($done[0]->created_at)->subMinutes(self::RUN_MINUTES);

        return array_values(array_filter($done, fn (AuditRecord $post): bool => $post->created_at >= $start));
    }

    private static function status(?PersonalAccessToken $token, ?CarbonImmutable $postedAt, CarbonImmutable $now): Status
    {
        if ($token === null) {
            return new Status('Not set up', Tone::Neutral->value);
        }

        if ($token->expires_at !== null && $token->expires_at->lte($now)) {
            return new Status('The token has expired. Revoke it and set up the bridge again.', Tone::Warning->value);
        }

        if ($postedAt === null) {
            return new Status('Waiting for the first post', Tone::Info->value);
        }

        $days = (int) $postedAt->startOfDay()->diffInDays($now->startOfDay());
        if ($days >= self::QUIET_DAYS) {
            return new Status("No posts for {$days} days. Check the automation is still on.", Tone::Warning->value);
        }

        return new Status('Last post '.self::when($postedAt, $now), Tone::Good->value);
    }

    /**
     * What the run carried, one line per kind, the newest value of each.
     *
     * @param  list<AuditRecord>  $run
     * @return list<LabelValue>
     */
    private static function items(array $run, CarbonImmutable $postedAt, CarbonImmutable $now): array
    {
        $items = [];
        foreach (self::LABELS as $capability => $label) {
            foreach ($run as $post) {
                if ($post->capability !== $capability) {
                    continue;
                }

                $value = self::carried($capability, $post->input ?? [], $postedAt, $now);
                if ($value !== null) {
                    $items[] = new LabelValue($label, $value);
                }
                break;
            }
        }

        return $items;
    }

    /**
     * One kind's value as the run sent it, with its day; a weight's time is enough on the day of the post.
     *
     * @param  array<array-key, mixed>  $input
     */
    private static function carried(string $capability, array $input, CarbonImmutable $postedAt, CarbonImmutable $now): ?string
    {
        if ($capability === 'steps_log') {
            if (! is_numeric($input['steps'] ?? null) || ! is_string($input['date'] ?? null)) {
                return null;
            }

            return number_format((int) $input['steps']).' steps on '.self::day(CarbonImmutable::parse($input['date']), $now);
        }

        $weight = $input['weight'] ?? null;
        if (! is_array($weight) || ! is_numeric($weight['value'] ?? null) || ! is_string($weight['unit'] ?? null) || ! is_string($input['weighed_at'] ?? null)) {
            return null;
        }

        $at = CarbonImmutable::parse($input['weighed_at'])->setTimezone($postedAt->getTimezone());
        $value = number_format((float) $weight['value'], 1).' '.$weight['unit'];

        return $at->isSameDay($postedAt)
            ? "{$value} at {$at->format('H:i')}"
            : "{$value} on ".self::day($at, $now)." at {$at->format('H:i')}";
    }

    /** "today, 06:02", "yesterday, 06:02", "30 October, 06:01", or with the year when it is not this one. */
    private static function when(CarbonImmutable $at, CarbonImmutable $now): string
    {
        return match (true) {
            $at->isSameDay($now) => 'today, '.$at->format('H:i'),
            $at->isSameDay($now->subDay()) => 'yesterday, '.$at->format('H:i'),
            default => self::day($at, $now).', '.$at->format('H:i'),
        };
    }

    private static function day(CarbonImmutable $day, CarbonImmutable $now): string
    {
        return $day->format($day->isSameYear($now) ? 'j F' : 'j F Y');
    }

    /**
     * The Shortcut's steps, to build it by hand.
     *
     * @return list<TextLine>
     */
    private static function steps(string $endpoint): array
    {
        return array_map(fn (string $text): TextLine => new TextLine($text), [
            'Set up the bridge here and copy the token. It is shown once.',
            'In the Shortcuts app, make a personal automation that runs every morning, set to run without asking.',
            'Add "Find Health Samples" for the latest Body Weight, and again for yesterday\'s Steps.',
            "Add \"Get Contents of URL\" to POST to {$endpoint}/weigh_in_log, with the header \"Authorization\" set to \"Bearer \" and your token, and a JSON body: \"id\", a new ULID each run; \"weighed_at\", the weight's date and time in ISO 8601; and \"weight\", with \"value\" and \"unit\" (\"kg\" or \"lb\").",
            "Add another \"Get Contents of URL\" to POST to {$endpoint}/steps_log, with the same header, and a JSON body: \"date\", yesterday as YYYY-MM-DD; and \"steps\", the sum of yesterday's steps.",
            'Run it once, then check this page shows the post.',
        ]);
    }
}
