import { beforeEach, describe, expect, it, vi } from 'vitest';
import { endSession } from '@/app/call';
import { signOut } from './signOut';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), endSession: vi.fn() }));

const endSessionMock = vi.mocked(endSession);

describe('signOut', () => {
    const calls: string[] = [];

    beforeEach(() => {
        calls.length = 0;
        endSessionMock.mockReset().mockImplementation(async () => void calls.push('endSession'));
    });

    it('NOTIF-010 turns push off for this browser before the session ends', async () => {
        await signOut({ disable: vi.fn(async () => void calls.push('disable')) });

        expect(calls).toEqual(['disable', 'endSession']);
    });

    it('ACC-006 still signs out when the server cannot be told to stop push', async () => {
        await signOut({ disable: vi.fn(async () => Promise.reject(new Error('network'))) });

        expect(endSessionMock).toHaveBeenCalledOnce();
    });
});
