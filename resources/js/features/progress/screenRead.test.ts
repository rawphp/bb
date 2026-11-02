import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import { readFailure, screenRead } from './screenRead';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
type Update = (data: string) => void;
type Fail = (error: CapabilityError) => void;

describe('readFailure', () => {
    it.each([
        [new CapabilityError('x', 0, null), { offline: true, error: null }],
        [new CapabilityError('x', 401, null), { offline: false, error: 'You are signed out.' }],
        [new CapabilityError('x', 500, null), { offline: false, error: 'Nope.' }],
        [new Error('boom'), { offline: false, error: 'Nope.' }],
    ])('CAP-012 OFF-003 words a failed read for the screen', (error, failure) => {
        expect(readFailure(error, 'Nope.')).toEqual(failure);
    });
});

describe('screenRead', () => {
    const shown: string[] = [];
    const failures: unknown[] = [];
    const load = () => screenRead<string>('x_show', 'Nope.', (data) => shown.push(data), (failure) => failures.push(failure));

    beforeEach(() => {
        readMock.mockReset();
        shown.length = 0;
        failures.length = 0;
    });

    it('CAP-004 shows what the read answers', async () => {
        readMock.mockResolvedValueOnce('fresh');
        await load()({ a: 1 });

        expect(readMock).toHaveBeenCalledWith('x_show', { a: 1 }, expect.any(Function), expect.any(Function));
        expect(shown).toEqual(['fresh']);
    });

    it('OFF-003 a refresh that lands before read() resolves wins over the cached copy', async () => {
        readMock.mockImplementationOnce(async (_n, _i, onUpdate) => {
            (onUpdate as Update)('fresh');
            return 'cached';
        });
        await load()({});

        expect(shown).toEqual(['fresh']);
    });

    it('OFF-003 a refresh that fails takes the cached copy away', async () => {
        let failRefresh: Fail = () => {};
        readMock.mockImplementationOnce(async (_n, _i, _u, onError) => {
            failRefresh = onError as Fail;
            return 'cached';
        });
        await load()({});
        failRefresh(new CapabilityError('x_show', 404, null));

        expect(shown).toEqual(['cached']);
        expect(failures).toEqual([{ offline: false, error: 'Nope.' }]);
    });

    it('OFF-003 a read that fails with no cached copy says why', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('x_show', 0, null));
        await load()({});

        expect(failures).toEqual([{ offline: true, error: null }]);
    });

    it('ANA-001 an answer to an older load never replaces a newer one', async () => {
        let refreshOld: Update = () => {};
        let failOld: Fail = () => {};
        readMock
            .mockImplementationOnce(async (_n, _i, onUpdate, onError) => {
                refreshOld = onUpdate as Update;
                failOld = onError as Fail;
                return 'old cached';
            })
            .mockResolvedValueOnce('new');
        const loader = load();
        await loader({ scope: 'phase' });
        await loader({ scope: 'plan' });

        refreshOld('old fresh');
        failOld(new CapabilityError('x_show', 500, null));

        expect(shown).toEqual(['old cached', 'new']);
        expect(failures).toEqual([]);
    });
});
