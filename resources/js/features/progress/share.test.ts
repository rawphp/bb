import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { sharePng, toPng } from './share';

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

describe('toPng', () => {
    const SVG = 'http://www.w3.org/2000/svg';
    // What the page's stylesheet gives each element, told apart by tag.
    const page: Record<string, Record<string, string>> = {
        svg: { fill: 'rgb(255, 255, 255)', 'font-family': 'Inter' },
        rect: { fill: 'rgb(10, 20, 30)', stroke: 'rgb(40, 50, 60)', 'stroke-width': '2px' },
        text: { fill: 'rgb(70, 80, 90)', 'font-size': '14px', 'font-weight': '600' },
    };
    let drawn: { canvas: HTMLCanvasElement; size: number[] }[];
    let serialized: SVGSVGElement[];
    let blob: Blob | null;

    function card(): SVGSVGElement {
        const svg = document.createElementNS(SVG, 'svg');
        Object.defineProperty(svg, 'viewBox', { value: { baseVal: { width: 360, height: 200 } } });
        svg.append(document.createElementNS(SVG, 'rect'), document.createElementNS(SVG, 'text'));
        return svg;
    }

    beforeEach(() => {
        drawn = [];
        serialized = [];
        blob = new Blob(['png'], { type: 'image/png' });
        URL.createObjectURL = vi.fn(() => 'blob:card');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(window, 'getComputedStyle').mockImplementation(
            (element) => ({ getPropertyValue: (name: string) => page[element.tagName]?.[name] ?? '' }) as CSSStyleDeclaration,
        );
        vi.spyOn(XMLSerializer.prototype, 'serializeToString').mockImplementation((node) => {
            serialized.push(node as SVGSVGElement);
            return '<svg/>';
        });
        Object.defineProperty(HTMLImageElement.prototype, 'decode', { configurable: true, value: vi.fn().mockResolvedValue(undefined) });
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(function (this: HTMLCanvasElement) {
            const canvas = this;
            return { drawImage: (_image: unknown, ...size: number[]) => drawn.push({ canvas, size }) } as unknown as CanvasRenderingContext2D;
        });
        vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(blob));
    });

    afterEach(() => {
        vi.restoreAllMocks();
        delete (HTMLImageElement.prototype as Partial<HTMLImageElement>).decode;
    });

    it('PWA-034 copies each element\'s page styles onto a copy of the card, so the picture keeps its colours away from the stylesheet', async () => {
        const svg = card();

        await toPng(svg, 2);

        const copy = serialized[0]!;
        expect(copy).not.toBe(svg);
        const [rect, text] = [copy.children[0] as SVGElement, copy.children[1] as SVGElement];
        expect(copy.style.getPropertyValue('fill')).toBe('rgb(255, 255, 255)');
        expect(copy.style.getPropertyValue('font-family')).toBe('Inter');
        expect(rect.style.getPropertyValue('fill')).toBe('rgb(10, 20, 30)');
        expect(rect.style.getPropertyValue('stroke')).toBe('rgb(40, 50, 60)');
        expect(rect.style.getPropertyValue('stroke-width')).toBe('2px');
        expect(text.style.getPropertyValue('fill')).toBe('rgb(70, 80, 90)');
        expect(text.style.getPropertyValue('font-size')).toBe('14px');
        expect(text.style.getPropertyValue('font-weight')).toBe('600');
        // The card on screen is left as it was.
        expect(svg.getAttribute('style')).toBeNull();
    });

    it('PWA-034 sizes the picture from the viewBox and draws it at the given pixel density', async () => {
        const svg = card();

        await expect(toPng(svg, 2)).resolves.toBe(blob);

        expect(serialized[0]!.getAttribute('width')).toBe('360');
        expect(serialized[0]!.getAttribute('height')).toBe('200');
        expect(drawn).toHaveLength(1);
        expect([drawn[0]!.canvas.width, drawn[0]!.canvas.height]).toEqual([720, 400]);
        expect(drawn[0]!.size).toEqual([0, 0, 720, 400]);
        expect(HTMLCanvasElement.prototype.toBlob).toHaveBeenCalledWith(expect.any(Function), 'image/png');
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:card');
    });

    it('PWA-034 fails when the canvas gives no picture, and still lets go of the SVG', async () => {
        blob = null;

        await expect(toPng(card(), 2)).rejects.toThrow('The progress image could not be drawn.');
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:card');
    });

    it('PWA-034 fails when the SVG cannot be decoded, and still lets go of it', async () => {
        const broken = new Error('decode failed');
        Object.defineProperty(HTMLImageElement.prototype, 'decode', { configurable: true, value: vi.fn().mockRejectedValue(broken) });

        await expect(toPng(card(), 2)).rejects.toBe(broken);
        expect(drawn).toHaveLength(0);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:card');
    });
});
