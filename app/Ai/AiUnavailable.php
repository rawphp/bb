<?php

namespace App\Ai;

use RuntimeException;

/**
 * The server has no Anthropic key, so no AI call is made. Thrown from a capability's run(), the
 * rail answers it as a refusal with this message (domain_error).
 */
final class AiUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('AI isn\'t set up on this server.');
    }
}
