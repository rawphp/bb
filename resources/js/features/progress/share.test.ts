import { describe, expect, it, vi } from 'vitest';
import { sharePng } from './share';

const png = new Blob(['png'], { type: 'image/png' });
const content = { title: 'My progress', message: 'Muscle gain: 83.2 kg to 84.3 kg' };

function fakeDocument() {
    const anchor = { href: '', download: '', click: vi.fn() };
    return { anchor, doc: { createElement: vi.fn(() => anchor) } as unknown as Document };
}

describe('sharePng', () => {
    it('PWA-034 shares the image through the Web Share API when the browser can share files', async () => {
        const share = vi.fn().mockResolvedValue(undefined);
        const nav = { canShare: vi.fn(() => true), share } as unknown as Navigator;
        const { doc, anchor } = fakeDocument();

        await expect(sharePng(png, content, nav, doc)).resolves.toBe('shared');

        const data = share.mock.calls[0]![0] as ShareData;
        expect(data.title).toBe('My progress');
        expect(data.text).toBe('Muscle gain: 83.2 kg to 84.3 kg');
        expect(data.files?.[0]?.type).toBe('image/png');
        expect(data.files?.[0]?.name).toBe('progress.png');
        expect(anchor.click).not.toHaveBeenCalled();
    });

    it('PWA-034 downloads the image when the browser cannot share files', async () => {
        const nav = { canShare: vi.fn(() => false), share: vi.fn() } as unknown as Navigator;
        const { doc, anchor } = fakeDocument();
        URL.createObjectURL = vi.fn(() => 'blob:progress');
        URL.revokeObjectURL = vi.fn();

        await expect(sharePng(png, content, nav, doc)).resolves.toBe('downloaded');

        expect(anchor.download).toBe('progress.png');
        expect(anchor.href).toBe('blob:progress');
        expect(anchor.click).toHaveBeenCalledOnce();
    });

    it('PWA-034 downloads the image when the browser has no Web Share API', async () => {
        const { doc, anchor } = fakeDocument();
        URL.createObjectURL = vi.fn(() => 'blob:progress');
        URL.revokeObjectURL = vi.fn();

        await expect(sharePng(png, content, {} as Navigator, doc)).resolves.toBe('downloaded');
        expect(anchor.click).toHaveBeenCalledOnce();
    });

    it('PWA-034 passes on a refused share rather than downloading behind the user\'s back', async () => {
        const refused = Object.assign(new Error('not allowed'), { name: 'NotAllowedError' });
        const nav = { canShare: () => true, share: vi.fn().mockRejectedValue(refused) } as unknown as Navigator;
        const { doc, anchor } = fakeDocument();

        await expect(sharePng(png, content, nav, doc)).rejects.toBe(refused);
        expect(anchor.click).not.toHaveBeenCalled();
    });

    it('PWA-034 treats closing the share sheet as a cancel, not a failure', async () => {
        const abort = Object.assign(new Error('closed'), { name: 'AbortError' });
        const nav = { canShare: () => true, share: vi.fn().mockRejectedValue(abort) } as unknown as Navigator;
        const { doc, anchor } = fakeDocument();

        await expect(sharePng(png, content, nav, doc)).resolves.toBe('cancelled');
        expect(anchor.click).not.toHaveBeenCalled();
    });
});
