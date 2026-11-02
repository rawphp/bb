import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, write } from './call';
import { useWrite } from './useWrite';

vi.mock('./call', async (original) => ({ ...(await original<typeof import('./call')>()), write: vi.fn() }));

const writeMock = vi.mocked(write);

describe('useWrite', () => {
    beforeEach(() => writeMock.mockReset());

    it('CAP-010 sends the write, then reads the screen again', async () => {
        writeMock.mockResolvedValueOnce({});
        const load = vi.fn(async () => {});
        const { send, error } = useWrite(load);

        expect(await send('priority_set', { a: 1 }, 'No.')).toBe('done');
        expect(writeMock).toHaveBeenCalledWith('priority_set', { a: 1 }, { idempotencyKey: expect.stringMatching(/^priority_set:/) });
        expect(load).toHaveBeenCalledTimes(1);
        expect(error.value).toBeNull();
    });

    it('CAP-012 keeps the server\'s words for a refused write and does not read again', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('priority_set', 422, { error: { message: 'This mesocycle has ended.' } }));
        const load = vi.fn(async () => {});
        const { send, error } = useWrite(load);

        expect(await send('priority_set', {}, 'No.')).toBe('refused');
        expect(error.value).toBe('This mesocycle has ended.');
        expect(load).not.toHaveBeenCalled();
    });

    it('OFF-003 reads the server again, not the cached copy, after a write timed out, which may have happened', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('priority_set', 0, { reason: 'timeout' }));
        const load = vi.fn(async () => {});
        const { send, error } = useWrite(load);

        expect(await send('priority_set', {}, 'No.')).toBe('no-answer');
        expect(load).toHaveBeenCalledExactlyOnceWith({ fresh: true });
        expect(error.value).toContain('may have worked');
    });

    it.each([
        [new CapabilityError('mesocycle_day_add', 502, null)],
        [new CapabilityError('mesocycle_day_add', 504, '<html>Gateway Timeout</html>')],
        [new CapabilityError('mesocycle_day_add', 409, { ok: false, error: { message: 'Still working.', retryable: true } })],
    ])('CAP-014 counts %s, with no final answer from the app, as no answer, and reads the server again', async (failure) => {
        writeMock.mockRejectedValueOnce(failure);
        const load = vi.fn(async () => {});
        const { send, error } = useWrite(load);

        expect(await send('mesocycle_day_add', {}, 'No.')).toBe('no-answer');
        expect(load).toHaveBeenCalledExactlyOnceWith({ fresh: true });
        expect(error.value).toContain('may have worked');
    });

    it.each([
        [new CapabilityError('mesocycle_day_add', 422, null)],
        [new CapabilityError('mesocycle_day_add', 404, null)],
        [new CapabilityError('mesocycle_day_add', 409, { ok: false, error: { message: 'Taken.' } })],
        [new CapabilityError('mesocycle_day_add', 500, { ok: false, error: { message: 'Server error.' } })],
    ])('CAP-014 counts the app\'s own answer %s as a refusal', async (failure) => {
        writeMock.mockRejectedValueOnce(failure);
        const { send } = useWrite(vi.fn(async () => {}));

        expect(await send('mesocycle_day_add', {}, 'No.')).toBe('refused');
    });

    it('OFF-003 does not read again when the device is offline', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('priority_set', 0, { reason: 'network' }));
        const load = vi.fn(async () => {});
        const { send } = useWrite(load);

        expect(await send('priority_set', {}, 'No.')).toBe('no-answer');
        expect(load).not.toHaveBeenCalled();
    });

    it('CAP-010 sends one write at a time and is busy until it has answered', async () => {
        let answer: (value: unknown) => void = () => {};
        writeMock.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
        const { send, busy } = useWrite(async () => {});

        const first = send('mesocycle_length_set', { weeks: 7 }, 'No.');
        expect(busy.value).toBe(true);
        expect(await send('mesocycle_length_set', { weeks: 7 }, 'No.')).toBeNull();
        expect(writeMock).toHaveBeenCalledTimes(1);

        answer({});
        expect(await first).toBe('done');
        await flushPromises();
        expect(busy.value).toBe(false);
    });

    it('OFF-011 keeps the write\'s message when the read after it fails too', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' }));
        const { send, error } = useWrite(vi.fn(async () => Promise.reject(new CapabilityError('mesocycle_show', 0, { reason: 'network' }))));

        expect(await send('mesocycle_day_add', {}, 'No.')).toBe('no-answer');
        expect(error.value).toContain('may have worked');
    });

    it('CAP-014 sends the same idempotency key when the same write is retried after no final answer', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' })).mockResolvedValueOnce({}).mockResolvedValueOnce({});
        const { send } = useWrite(vi.fn(async () => {}));

        await send('mesocycle_day_add', { day_id: 'a' }, 'No.');
        await send('mesocycle_day_add', { day_id: 'a' }, 'No.');
        await send('mesocycle_day_add', { day_id: 'a' }, 'No.');

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[0]).toMatch(/^mesocycle_day_add:/);
        expect(keys[1]).toBe(keys[0]);
        expect(keys[2]).not.toBe(keys[1]);
    });

    it.each([
        ['other input', 'mesocycle_day_add', { day_id: 'b' }],
        ['another write', 'mesocycle_end', { day_id: 'a' }],
    ])('CAP-014 sends a new key for %s after no final answer, since that is a new request', async (_label, name, input) => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' })).mockResolvedValueOnce({});
        const { send } = useWrite(vi.fn(async () => {}));

        await send('mesocycle_day_add', { day_id: 'a' }, 'No.');
        await send(name, input, 'No.');

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).not.toBe(keys[0]);
    });

    it('CAP-014 sends a new key after the app refused the write', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 422, null)).mockResolvedValueOnce({});
        const { send } = useWrite(vi.fn(async () => {}));

        await send('mesocycle_day_add', { day_id: 'a' }, 'No.');
        await send('mesocycle_day_add', { day_id: 'a' }, 'No.');

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).not.toBe(keys[0]);
    });
});
