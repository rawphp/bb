import { describe, expect, it } from 'vitest';
import { CapabilityError } from './call';
import { useRetryKey } from './retryKey';

describe('useRetryKey', () => {
    it('CAP-014 names the write in a valid idempotency key', () => {
        expect(useRetryKey('token_revoke').key.value).toMatch(/^token_revoke:[0-9A-HJKMNP-TV-Z]{26}$/);
    });

    it('CAP-014 keeps the key while the last attempt got no answer', () => {
        const retry = useRetryKey('account_delete');
        const first = retry.key.value;

        retry.settle(new CapabilityError('account_delete', 0, { reason: 'timeout' }));

        expect(retry.key.value).toBe(first);
        expect(retry.unsure.value).toBe(true);
    });

    it.each([
        ['a gateway timeout, which is a proxy page and not the app\'s answer', new CapabilityError('account_delete', 504, null)],
        ['a bad gateway with an HTML body', new CapabilityError('account_delete', 502, null)],
        ['the server still running the first attempt', new CapabilityError('account_delete', 409, { ok: false, error: { code: 'conflict', retryable: true } })],
    ])('CAP-014 keeps the key after %s, since that attempt may have run', (_label, error) => {
        const retry = useRetryKey('account_delete');
        const first = retry.key.value;

        retry.settle(error);

        expect(retry.key.value).toBe(first);
        expect(retry.unsure.value).toBe(true);
    });

    it.each([
        ['a success', undefined],
        ['a refusal', new CapabilityError('account_delete', 422, { ok: false, error: { code: 'validation_failed' } })],
        ['a conflict the server keeps', new CapabilityError('account_delete', 409, { ok: false, error: { code: 'conflict', retryable: false } })],
        ['the app\'s own server error', new CapabilityError('account_delete', 500, { ok: false, error: { code: 'internal' } })],
        ['a gone session', new CapabilityError('account_delete', 401, { message: 'Unauthenticated.' })],
    ])('CAP-014 ends the key after %s, since the server keeps that answer under it', (_label, error) => {
        const retry = useRetryKey('account_delete');
        const first = retry.key.value;
        retry.settle(new CapabilityError('account_delete', 0, null));

        retry.settle(error);

        expect(retry.key.value).not.toBe(first);
        expect(retry.unsure.value).toBe(false);
    });
});
