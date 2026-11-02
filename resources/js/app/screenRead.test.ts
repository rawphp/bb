import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import { readFailure, screenRead } from './screenRead';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
type Update = (data: string) => void;
type Fail = (error: CapabilityError) => void;

describe('readFailure', () => {
    it.each([
        [new CapabilityError('x', 0, null), { offline: true, error: null, signedOut: false }],
        [new CapabilityError('x', 401, null), { offline: false, error: 'You are signed out.', signedOut: true }],
        [new CapabilityError('x', 419, null), { offline: false, error: 'Your session has ended. Sign in again.', signedOut: true }],
        [new CapabilityError('x', 500, null), { offline: false, error: 'Nope.', signedOut: false }],
        [new Error('boom'), { offline: false, error: 'Nope.', signedOut: false }],
    ])('CAP-012 OFF-003 words a failed read for the screen', (error, failure) => {
        expect(readFailure(error, 'Nope.')).toEqual(failure);
    });
});

describe('screenRead', () => {
    const shown: string[] = [];
    const failures: unknown[] = [];
    const load = () => screenRead<string>('x_show', 'Nope.', (data) => shown.push(data), (failure, hadData) => failures.push({ ...failure, hadData }));

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
        // This load showed a copy, so the screen must take it away (OFF-003).
        expect(failures).toEqual([{ offline: false, error: 'Nope.', signedOut: false, hadData: true }]);
    });

    it('OFF-003 a read that fails with no cached copy says why', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('x_show', 0, null));
        await load()({});

        expect(failures).toEqual([{ offline: true, error: null, signedOut: false, hadData: false }]);
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

    it('OFF-003 an older load whose shown copy is refused takes it away while the newer one waits', async () => {
        let failOld: Fail = () => {};
        readMock
            .mockImplementationOnce(async (_n, _i, _u, onError) => {
                failOld = onError as Fail;
                return 'old cached';
            })
            .mockImplementationOnce(() => new Promise(() => {}));
        const loader = load();
        await loader({ scope: 'phase' });
        void loader({ scope: 'plan' });

        failOld(new CapabilityError('x_show', 403, null));

        expect(shown).toEqual(['old cached']);
        expect(failures).toEqual([{ offline: false, error: 'Nope.', signedOut: false, hadData: true }]);
    });

    it('OFF-003 an older load refused after a newer one showed its copy changes nothing', async () => {
        let failOld: Fail = () => {};
        readMock
            .mockImplementationOnce(async (_n, _i, _u, onError) => {
                failOld = onError as Fail;
                return 'old cached';
            })
            .mockResolvedValueOnce('new');
        const loader = load();
        await loader({ scope: 'phase' });
        await loader({ scope: 'plan' });

        failOld(new CapabilityError('x_show', 403, null));

        expect(shown).toEqual(['old cached', 'new']);
        expect(failures).toEqual([]);
    });
});
