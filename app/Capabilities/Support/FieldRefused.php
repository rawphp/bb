<?php

namespace App\Capabilities\Support;

use Closure;
use DomainException;

/**
 * A refusal that names the input field at fault (CAP-012), for a check only the run can make, such as a choice that
 * must be one the current plan offers. The package answers any exception from a run with a message-only
 * `domain_error`, so {@see HostBus} runs each invoke inside {@see watch()} and turns that answer into a 422 whose
 * violation names the field. Invokes that bypass the bus (the job and MCP surfaces) keep the message without it.
 */
final class FieldRefused extends DomainException
{
    /** The refusal thrown in the innermost open watch. */
    private static ?self $thrown = null;

    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
        self::$thrown = $this;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $invoke
     * @return array{0: T, 1: ?self} the result, and the refusal thrown while it ran
     */
    public static function watch(Closure $invoke): array
    {
        $outer = self::$thrown;
        self::$thrown = null;
        try {
            $result = $invoke();

            return [$result, self::$thrown];
        } finally {
            self::$thrown = $outer;
        }
    }
}
