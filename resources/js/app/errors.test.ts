import { describe, expect, it } from 'vitest';
import { CapabilityError } from './call';
import { failureMessage } from './errors';

describe('failureMessage', () => {
    it('CAP-012 shows the message the server wrote', () => {
        const error = new CapabilityError('token_create', 422, { ok: false, error: { code: 'schema_invalid', message: 'Give the token a name.' } });

        expect(failureMessage(error, 'Could not save.')).toBe('Give the token a name.');
    });

    it.each([
        [new CapabilityError('token_create', 500, null)],
        [new CapabilityError('token_create', 422, { ok: false, error: { message: '' } })],
        [new Error('boom')],
    ])('CAP-012 falls back when the server wrote nothing', (error) => {
        expect(failureMessage(error, 'Could not save.')).toBe('Could not save.');
    });

    it('OFF-003 says a call needs a connection when none arrived', () => {
        const error = new CapabilityError('token_create', 0, { reason: 'network' });

        expect(failureMessage(error, 'Could not save.')).toBe('No connection. Try again when you are online.');
    });
});
