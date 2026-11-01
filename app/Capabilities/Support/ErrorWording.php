<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Support\CapabilityResult;

/**
 * Puts the package's own error messages into plain words (CAP-012), and
 * answers an Idempotency-Key reused with different input with 422 (CAP-014).
 *
 * Messages the host wrote (a domain refusal, a tenant resolver's refusal, a
 * Laravel rule message) pass through unchanged. Only package wording is
 * replaced: by code for codes only the package emits, else by its exact message.
 */
final class ErrorWording
{
    private const KEY_REUSED = 'Idempotency key reused with a different request body (D-005).';

    private const TRY_LATER = 'Something went wrong. Try again later.';

    /**
     * Codes only the package emits: a capability cannot throw them
     * (CapabilityFailure has no constructor for them), so every message is the package's.
     *
     * @var array<string, string> code => plain message
     */
    private const PACKAGE_CODES = [
        'capability_not_in_profile' => 'You can\'t do that.',
        'output_invalid' => self::TRY_LATER,
        'audit_failed' => self::TRY_LATER,
        'not_configured' => self::TRY_LATER,
        'not_runnable' => self::TRY_LATER,
    ];

    /**
     * The package's own messages under codes the host can also use (a tenant
     * resolver's refusal is a host-written `forbidden`), matched in full.
     *
     * @var array<string, array<string, string>> code => [message pattern => plain message]
     */
    private const PACKAGE_MESSAGES = [
        'conflict' => [
            '/^Idempotency key is already processing \(D-005\)\.$/' => 'This change is still being saved. Try again in a moment.',
        ],
        'validation_failed' => [
            '/^Invalid idempotency key format \(D-005\)\.$/' => 'The Idempotency-Key header is not a valid key.',
            '/^Idempotency key is required for this capability \(D-005\)\.$/' => 'This change needs an Idempotency-Key header.',
            '/^JSON Schema validation failed\.$/' => 'Some details need fixing.',
            '/^Server-only validation failed\.$/' => 'Some details need fixing.',
        ],
        'forbidden' => [
            '/^Not authorized to invoke ".+"\.$/' => 'You can\'t do that.',
            '/^SystemActor ".+" is not allowed for capability ".+"\.$/' => 'You can\'t do that.',
            '/^Capability ".+" is not invokable via surface ".+"\.$/' => 'You can\'t do that.',
            '/^Original actor no longer authorized\.$/' => 'You can\'t do that.',
            '/^Original requester could not be resolved; approved request was not run\.$/' => 'You can\'t do that.',
        ],
        'not_found' => [
            '/^Not found\.$/' => 'We couldn\'t find that.',
            '/^Unknown capability ".+"\.$/' => 'We couldn\'t find that.',
            '/^Approval not found\.$/' => 'We couldn\'t find that.',
        ],
        'internal' => [
            '/^Internal error\.$/' => self::TRY_LATER,
        ],
    ];

    /** @var array<string, string> package violation pattern => plain message */
    private const VIOLATIONS = [
        '/^required property missing$/' => 'This field is required.',
        '/^additional property not allowed$/' => 'This field is not accepted.',
        '/^expected type /' => 'This value is the wrong type.',
        '/^value not in enum$/' => 'Choose one of the allowed values.',
        '/^invalid \S+ format$/' => 'This value is not in the right format.',
        '/^below minimum$/' => 'This value is too small.',
        '/^above maximum$/' => 'This value is too large.',
        '/^shorter than minLength$/' => 'This is too short.',
        '/^longer than maxLength$/' => 'This is too long.',
        '/^fewer items than minItems$/' => 'Add more items.',
        '/^more items than maxItems$/' => 'Remove some items.',
    ];

    public static function apply(CapabilityResult $result): CapabilityResult
    {
        if ($result->isOk() || $result->isApprovalRequired()) {
            return $result;
        }

        $error = $result->error ?? [];
        $code = (string) $result->errorCode();
        $message = (string) ($error['message'] ?? '');

        if ($code === 'conflict' && $message === self::KEY_REUSED) {
            return CapabilityResult::failure(
                code: 'validation_failed',
                message: 'This request was already sent with different details.',
                extra: ['violations' => [
                    ['field' => 'idempotency_key', 'message' => 'Use a new key for a different request.'],
                ]],
                meta: $result->meta,
            );
        }

        $plain = self::PACKAGE_CODES[$code] ?? self::packageMessage($code, $message);
        $violations = self::violations($error['violations'] ?? []);

        if ($plain === null && $violations === ($error['violations'] ?? [])) {
            return $result;
        }

        $extra = $error;
        unset($extra['code'], $extra['message']);
        $extra['violations'] = $violations;

        return CapabilityResult::failure($code, $plain ?? $message, $extra, $result->meta);
    }

    private static function packageMessage(string $code, string $message): ?string
    {
        foreach (self::PACKAGE_MESSAGES[$code] ?? [] as $pattern => $plain) {
            if (preg_match($pattern, $message) === 1) {
                return $plain;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private static function violations(mixed $violations): array
    {
        if (! is_array($violations)) {
            return [];
        }

        return array_map(function (mixed $violation): mixed {
            if (! is_array($violation) || ! is_string($violation['message'] ?? null)) {
                return $violation;
            }

            foreach (self::VIOLATIONS as $pattern => $plain) {
                if (preg_match($pattern, $violation['message']) === 1) {
                    return [...$violation, 'message' => $plain];
                }
            }

            return $violation;
        }, $violations);
    }
}
