import { describe, expect, it } from 'vitest';
import { CapabilityError } from './call';
import { failureMessage, isTimeout, writeFailureMessage } from './errors';

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

    it('OFF-003 says a call past its deadline may have worked, rather than that the device is offline', () => {
        const error = new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' });

        expect(failureMessage(error, 'Could not save.')).toBe('The server did not answer in time, so this may have worked. Check, then try again.');
        expect(isTimeout(error)).toBe(true);
        expect(isTimeout(new CapabilityError('mesocycle_day_add', 0, { reason: 'network' }))).toBe(false);
        expect(isTimeout(new CapabilityError('mesocycle_day_add', 504, { reason: 'timeout' }))).toBe(false);
    });
});

describe('writeFailureMessage', () => {
    it.each([
        [new CapabilityError('mesocycle_day_add', 504, null)],
        [new CapabilityError('mesocycle_day_add', 500, { ok: false, error: { message: 'Server error.' } })],
        [new CapabilityError('mesocycle_day_add', 200, null)],
    ])('OFF-011 says a write that got a 5xx or an unreadable answer may have worked', (error) => {
        expect(writeFailureMessage(error, 'The day could not be added.')).toBe('The server had a problem answering, so this may have worked. Check, then try again.');
    });

    it.each([
        [new CapabilityError('mesocycle_day_add', 422, { ok: false, error: { message: 'No room for another day.' } }), 'No room for another day.'],
        [new CapabilityError('mesocycle_day_add', 0, { reason: 'network' }), 'No connection. Try again when you are online.'],
        [new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' }), 'The server did not answer in time, so this may have worked. Check, then try again.'],
        [new Error('boom'), 'The day could not be added.'],
    ])('CAP-012 otherwise says what failureMessage says', (error, message) => {
        expect(writeFailureMessage(error, 'The day could not be added.')).toBe(message);
    });
});
