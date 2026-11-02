import { CapabilityError } from './call';

// A refused postHost() call, as a form shows it. Host routes answer in Laravel's shape,
// not the capability envelope that errors.ts reads: a 422 holds each field's errors
// under `errors`, and the throttle answers 429.
export type HostFailure<F extends string> = {
    /** The first error of each field the form shows. */
    fields: Partial<Record<F, string>>;
    /** One message for the whole form, when no shown field has an error. */
    message: string | null;
    /** The device is already signed in, so the host route refused (postHost). */
    signedIn: boolean;
};

const FALLBACK = 'Something went wrong. Try again.';

export function hostFailure<F extends string>(error: unknown, shown: readonly F[]): HostFailure<F> {
    const whole = (message: string, signedIn = false): HostFailure<F> => ({ fields: {}, message, signedIn });
    if (!(error instanceof CapabilityError)) return whole(FALLBACK);

    const body = error.body as { message?: unknown; errors?: Record<string, unknown>; reason?: unknown } | null;
    if (error.status === 0) return whole(body?.reason === 'timeout' ? 'The server did not answer in time. Try again.' : 'No connection. Try again when you are online.');
    if (error.status === 302) return whole('You are already signed in on this device.', true);
    if (error.status === 429) return whole('Too many tries. Wait a few minutes, then try again.');
    if (error.status !== 422) return whole(FALLBACK);

    const fields: Partial<Record<F, string>> = {};
    for (const field of shown) {
        const first = (body?.errors?.[field] as unknown[] | undefined)?.[0];
        if (typeof first === 'string') fields[field] = first;
    }
    if (Object.keys(fields).length > 0) return { fields, message: null, signedIn: false };

    return whole(typeof body?.message === 'string' && body.message !== '' ? body.message : FALLBACK);
}
