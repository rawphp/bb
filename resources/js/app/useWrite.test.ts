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
        expect(writeMock).toHaveBeenCalledWith('priority_set', { a: 1 });
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

    it.each([500, 502, 504, 200])('OFF-011 counts a %i answer as no answer, not a refusal, and reads the server again', async (status) => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', status, null));
        const load = vi.fn(async () => {});
        const { send } = useWrite(load);

        expect(await send('mesocycle_day_add', {}, 'No.')).toBe('no-answer');
        expect(load).toHaveBeenCalledExactlyOnceWith({ fresh: true });
    });

    it.each([400, 404, 422, 499])('OFF-011 counts a %i answer as a refusal', async (status) => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', status, null));
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
});
