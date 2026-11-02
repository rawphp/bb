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
        ['a success', undefined],
        ['a refusal', new CapabilityError('account_delete', 422, null)],
        ['a server error', new CapabilityError('account_delete', 500, null)],
    ])('CAP-014 ends the key after %s, since the server keeps that answer under it', (_label, error) => {
        const retry = useRetryKey('account_delete');
        const first = retry.key.value;
        retry.settle(new CapabilityError('account_delete', 0, null));

        retry.settle(error);

        expect(retry.key.value).not.toBe(first);
        expect(retry.unsure.value).toBe(false);
    });
});
