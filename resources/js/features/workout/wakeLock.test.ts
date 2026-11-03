import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { holdWakeLock, LEAVE_AFTER_MS, leaveWakeLock, releaseWakeLock } from './wakeLock';

function fakeLock() {
    const lock = { release: vi.fn(() => Promise.resolve()), addEventListener: vi.fn() };
    const request = vi.fn(() => Promise.resolve(lock));
    Object.defineProperty(navigator, 'wakeLock', { value: { request }, configurable: true });
    return { lock, request };
}

function visibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
    document.dispatchEvent(new Event('visibilitychange'));
}

describe('wake lock', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        visibility('visible');
    });

    afterEach(() => {
        releaseWakeLock();
        vi.useRealTimers();
    });

    it('PWA-030 holds a screen wake lock during the workout and lets it go on finish', async () => {
        const { lock, request } = fakeLock();

        holdWakeLock();
        await vi.waitFor(() => expect(request).toHaveBeenCalledWith('screen'));
        await Promise.resolve();
        releaseWakeLock();

        expect(lock.release).toHaveBeenCalled();
    });

    it('PWA-030 leaving the workout lets it go after 30 minutes, not before', async () => {
        const { lock, request } = fakeLock();
        holdWakeLock();
        await vi.waitFor(() => expect(request).toHaveBeenCalled());
        await Promise.resolve();

        leaveWakeLock();
        vi.advanceTimersByTime(LEAVE_AFTER_MS - 1);
        expect(lock.release).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);

        expect(lock.release).toHaveBeenCalled();
        expect(LEAVE_AFTER_MS).toBe(30 * 60_000);
    });

    it('PWA-030 coming back within 30 minutes keeps it', async () => {
        const { lock, request } = fakeLock();
        holdWakeLock();
        await vi.waitFor(() => expect(request).toHaveBeenCalled());
        await Promise.resolve();

        leaveWakeLock();
        vi.advanceTimersByTime(LEAVE_AFTER_MS / 2);
        holdWakeLock();
        vi.advanceTimersByTime(LEAVE_AFTER_MS);

        expect(lock.release).not.toHaveBeenCalled();
    });

    it('PWA-030 a page hidden for over 30 minutes lets it go; back sooner, it takes the lock again', async () => {
        const { lock, request } = fakeLock();
        holdWakeLock();
        await vi.waitFor(() => expect(request).toHaveBeenCalledTimes(1));
        await Promise.resolve();
        // The browser drops the lock while hidden.
        const dropped = lock.addEventListener.mock.calls.find(([type]) => type === 'release')![1] as () => void;
        visibility('hidden');
        dropped();
        visibility('visible');
        await vi.waitFor(() => expect(request).toHaveBeenCalledTimes(2));

        visibility('hidden');
        vi.advanceTimersByTime(LEAVE_AFTER_MS);
        visibility('visible');
        await Promise.resolve();

        expect(request).toHaveBeenCalledTimes(2);
    });

    it('PWA-030 a browser with no wake lock carries on without one', () => {
        Object.defineProperty(navigator, 'wakeLock', { value: undefined, configurable: true });
        // @ts-expect-error removing the API
        delete navigator.wakeLock;

        expect(() => holdWakeLock()).not.toThrow();
    });
});
