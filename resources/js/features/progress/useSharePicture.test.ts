import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, ref, type EffectScope } from 'vue';
import { sharePng, toPng } from './share';
import { useSharePicture } from './useSharePicture';

vi.mock('./share', () => ({ toPng: vi.fn(), sharePng: vi.fn() }));

const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
const phase = { title: 'My progress', message: 'Phase' };
const plan = { title: 'My progress', message: 'Whole plan' };

// A promise the test settles by hand.
function pending() {
    let resolve: (png: Blob) => void = () => {};
    let reject: (error: Error) => void = () => {};
    const promise = new Promise<Blob>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}

describe('useSharePicture', () => {
    let scope: EffectScope;
    const content = ref<typeof phase | null>(phase);

    function use() {
        return scope.run(() => useSharePicture(() => svg, () => [content.value] as const))!;
    }

    beforeEach(() => {
        scope = effectScope();
        content.value = phase;
        vi.mocked(toPng).mockReset();
        vi.mocked(sharePng).mockReset().mockResolvedValue('shared');
    });
    afterEach(() => scope.stop());

    it('PWA-034 draws as soon as there is something to share, and a tap shares it with no wait', async () => {
        const png = new Blob(['png']);
        vi.mocked(toPng).mockResolvedValue(png);
        const picture = use();
        expect(picture.drawing.value).toBe(true);
        await flushPromises();
        expect(picture.drawing.value).toBe(false);
        expect(toPng).toHaveBeenCalledWith(svg);

        void picture.share(phase);

        expect(sharePng).toHaveBeenCalledWith(png, phase);
        expect(toPng).toHaveBeenCalledOnce();
    });

    it('PWA-034 draws nothing until every part is there', async () => {
        content.value = null;
        use();
        await flushPromises();

        expect(toPng).not.toHaveBeenCalled();
    });

    it('PWA-034 a tap during a draw that then fails draws again and shares that', async () => {
        const first = pending();
        const png = new Blob(['second']);
        vi.mocked(toPng).mockReturnValueOnce(first.promise).mockResolvedValueOnce(png);
        const picture = use();
        await flushPromises();

        const tap = picture.share(phase);
        await flushPromises();
        expect(sharePng).not.toHaveBeenCalled();
        first.reject(new Error('decode failed'));
        await tap;

        expect(toPng).toHaveBeenCalledTimes(2);
        expect(sharePng).toHaveBeenCalledWith(png, phase);
        expect(picture.shareError.value).toBeNull();
    });

    it('PWA-034 a tap during a draw waits for it rather than drawing twice', async () => {
        const first = pending();
        const png = new Blob(['png']);
        vi.mocked(toPng).mockReturnValueOnce(first.promise);
        const picture = use();

        const tap = picture.share(phase);
        first.resolve(png);
        await tap;

        expect(toPng).toHaveBeenCalledOnce();
        expect(sharePng).toHaveBeenCalledWith(png, phase);
    });

    it('PWA-034 a new subject drops the old picture, so a tap waits for the new one', async () => {
        const old = new Blob(['phase']);
        const next = pending();
        const fresh = new Blob(['plan']);
        vi.mocked(toPng).mockResolvedValueOnce(old).mockReturnValueOnce(next.promise);
        const picture = use();
        await flushPromises();

        content.value = plan;
        await flushPromises();
        const tap = picture.share(plan);
        await flushPromises();
        expect(sharePng).not.toHaveBeenCalled();
        next.resolve(fresh);
        await tap;

        expect(sharePng).toHaveBeenCalledTimes(1);
        expect(sharePng).toHaveBeenCalledWith(fresh, plan);
    });

    it('PWA-034 a slow draw of the old subject never replaces the new picture, nor unmounts its card', async () => {
        const slow = pending();
        const next = pending();
        const fresh = new Blob(['plan']);
        vi.mocked(toPng).mockReturnValueOnce(slow.promise).mockReturnValueOnce(next.promise);
        const picture = use();
        await flushPromises();

        content.value = plan;
        await flushPromises();
        slow.resolve(new Blob(['phase']));
        await flushPromises();
        // The new picture is still being drawn from the mounted card.
        expect(picture.drawing.value).toBe(true);
        next.resolve(fresh);
        await flushPromises();
        expect(picture.drawing.value).toBe(false);
        void picture.share(plan);

        expect(sharePng).toHaveBeenCalledWith(fresh, plan);
    });

    it('PWA-034 a second tap while sharing does nothing', async () => {
        vi.mocked(toPng).mockResolvedValue(new Blob(['png']));
        const open = pending();
        vi.mocked(sharePng).mockReturnValueOnce(open.promise.then(() => 'shared' as const));
        const picture = use();
        await flushPromises();

        void picture.share(phase);
        expect(picture.sharing.value).toBe(true);
        await picture.share(phase);

        expect(sharePng).toHaveBeenCalledOnce();
        open.resolve(new Blob());
        await flushPromises();
        expect(picture.sharing.value).toBe(false);
    });

    it('PWA-034 says when the picture could not be shared, and clears it on the next tap', async () => {
        vi.mocked(toPng).mockResolvedValue(new Blob(['png']));
        vi.mocked(sharePng).mockRejectedValueOnce(new Error('refused'));
        const picture = use();
        await flushPromises();

        await picture.share(phase);
        expect(picture.shareError.value).toBe('The progress picture could not be shared.');

        await picture.share(phase);
        expect(picture.shareError.value).toBeNull();
    });
});
