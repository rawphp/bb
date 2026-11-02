import { describe, expect, it } from 'vitest';
import { CapabilityError } from './call';
import { hostFailure } from './hostFailure';

const FIELDS = ['name', 'email', 'password', 'code'] as const;
const refused = (status: number, body: unknown) => hostFailure(new CapabilityError('/sign-in', status, body), FIELDS);

describe('hostFailure', () => {
    it('ACC-001 a 422 gives each shown field its first error, and no message', () => {
        const failure = refused(422, {
            message: 'The email has already been taken. (and 1 more error)',
            errors: { email: ['The email has already been taken.'], password: ['Too short.', 'Not confirmed.'] },
        });

        expect(failure).toEqual({ fields: { email: 'The email has already been taken.', password: 'Too short.' }, message: null, signedIn: false });
    });

    it('ACC-001 a 422 on no shown field gives the server\'s message', () => {
        expect(refused(422, { message: 'Something is off.', errors: { remember: ['Something is off.'] } })).toEqual({
            fields: {},
            message: 'Something is off.',
            signedIn: false,
        });
    });

    it.each([
        ['rate-limited (429)', 429, { message: 'Too Many Attempts.' }, 'Too many tries. Wait a few minutes, then try again.'],
        ['offline', 0, { reason: 'network' }, 'No connection. Try again when you are online.'],
        ['past its deadline', 0, { reason: 'timeout' }, 'The server did not answer in time. Try again.'],
        ['a stale CSRF token (419)', 419, { message: 'CSRF token mismatch.' }, 'Something went wrong. Try again.'],
        ['a server failure (500)', 500, { message: 'Server Error' }, 'Something went wrong. Try again.'],
        ['a 422 with no words', 422, null, 'Something went wrong. Try again.'],
    ])('ACC-001 %s says so for the whole form', (_, status, body, message) => {
        expect(refused(status, body)).toEqual({ fields: {}, message, signedIn: false });
    });

    it('ACC-001 a session already signed in says so, and flags it', () => {
        expect(refused(302, { reason: 'signed_in' })).toEqual({ fields: {}, message: 'You are already signed in on this device.', signedIn: true });
    });

    it('ACC-001 anything that is not a refused call is a plain failure', () => {
        expect(hostFailure(new Error('boom'), FIELDS)).toEqual({ fields: {}, message: 'Something went wrong. Try again.', signedIn: false });
    });
});
