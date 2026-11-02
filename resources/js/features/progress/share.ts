// Share progress (ANA-010, PWA-034): the share card becomes a PNG, which goes to
// the Web Share API, or downloads where the browser cannot share files.

export type ShareOutcome = 'shared' | 'downloaded' | 'cancelled';

const FILE_NAME = 'progress.png';
// Copied from the page onto each element, so the picture keeps its colours and type
// once it is drawn on its own, away from the stylesheet.
const STYLES = ['fill', 'stroke', 'stroke-width', 'stroke-dasharray', 'opacity', 'font-family', 'font-size', 'font-weight'] as const;

/** Draws an on-screen <svg> as a PNG at the device's pixel density. */
export async function toPng(svg: SVGSVGElement, scale = window.devicePixelRatio || 2): Promise<Blob> {
    const copy = svg.cloneNode(true) as SVGSVGElement;
    const from = [svg, ...svg.querySelectorAll('*')];
    const to = [copy, ...copy.querySelectorAll('*')];
    from.forEach((element, i) => {
        const style = getComputedStyle(element);
        const target = to[i] as SVGElement;
        for (const name of STYLES) target.style.setProperty(name, style.getPropertyValue(name));
    });

    const { width, height } = svg.viewBox.baseVal;
    copy.setAttribute('width', String(width));
    copy.setAttribute('height', String(height));
    const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(copy)], { type: 'image/svg+xml' }));

    try {
        const image = new Image();
        image.src = url;
        await image.decode();

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        canvas.getContext('2d')!.drawImage(image, 0, 0, canvas.width, canvas.height);

        return await new Promise<Blob>((resolve, reject) =>
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('The progress image could not be drawn.'))), 'image/png'),
        );
    } finally {
        URL.revokeObjectURL(url);
    }
}

export async function sharePng(
    png: Blob,
    content: { title: string; message: string },
    nav: Navigator = navigator,
    doc: Document = document,
): Promise<ShareOutcome> {
    const file = new File([png], FILE_NAME, { type: 'image/png' });
    const data: ShareData = { files: [file], title: content.title, text: content.message };

    if (typeof nav.share === 'function' && nav.canShare?.(data)) {
        try {
            await nav.share(data);
            return 'shared';
        } catch (error) {
            if (error instanceof Error && error.name === 'AbortError') return 'cancelled';
            throw error;
        }
    }

    const url = URL.createObjectURL(png);
    const link = doc.createElement('a');
    link.href = url;
    link.download = FILE_NAME;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 0);
    return 'downloaded';
}
