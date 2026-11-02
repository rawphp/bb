<?php

namespace App\Ai;

use RuntimeException;

/**
 * The AI call failed, or answered with something unusable. The message is written for the user
 * and never carries the provider's reply, which can quote the request.
 */
final class AiFailed extends RuntimeException
{
    public function __construct(string $message = 'The AI request failed. Try again.')
    {
        parent::__construct($message);
    }
}
