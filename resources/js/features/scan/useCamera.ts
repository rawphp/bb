import { onScopeDispose, ref, type Ref } from 'vue';

// The scanner's camera and photos (FOOD-040, PWA-031, PWA-032, PLT-003), ported from rp-clone's ScanImages.swift.
// Barcode mode reads codes from the rear camera's live picture: with the browser's BarcodeDetector where it has
// one, and zxing-wasm where it doesn't (iOS Safari). Label and photo modes upload a still photo, shrunk first.

/** ready: there is a camera to ask for; denied: the browser refuses it; none: the device has no camera. */
export type CameraAccess = 'ready' | 'denied' | 'none';

/** The longest side of an uploaded photo, in pixels. */
const MAX_SIDE = 2048;
/** scan_photo and scan_label take a data URL of up to 2,000,000 characters. */
const MAX_LENGTH = 2_000_000;
/** How often the live picture is read, in milliseconds. */
const READ_EVERY = 250;

type Frame = HTMLVideoElement | Blob;
type Decode = (frame: Frame) => Promise<string[]>;

interface Detector {
    detect(source: ImageBitmapSource): Promise<{ rawValue: string }[]>;
}
interface DetectorClass {
    new (): Detector;
    getSupportedFormats(): Promise<string[]>;
}

let wasm: Promise<Decode> | undefined;

/** The browser's BarcodeDetector when it reads anything, else zxing-wasm, loaded once on first use. */
async function decode(): Promise<Decode> {
    const native = await nativeDecoder();
    if (native) return native;
    wasm ??= wasmDecoder();

    return wasm;
}

async function nativeDecoder(): Promise<Decode | null> {
    const Native = (globalThis as { BarcodeDetector?: DetectorClass }).BarcodeDetector;
    const formats = await Native?.getSupportedFormats().catch(() => []);
    if (!Native || !formats?.length) return null;
    const detector = new Native();

    return async (frame) => {
        const bitmap = frame instanceof Blob ? await createImageBitmap(frame) : null;
        try {
            return (await detector.detect(bitmap ?? (frame as HTMLVideoElement))).map((code) => code.rawValue);
        } finally {
            bitmap?.close();
        }
    };
}

async function wasmDecoder(): Promise<Decode> {
    const [{ prepareZXingModule, readBarcodes }, { default: url }] = await Promise.all([
        import('zxing-wasm/reader'),
        import('zxing-wasm/reader/zxing_reader.wasm?url'),
    ]);
    // The wasm ships with the app, not from a CDN.
    prepareZXingModule({ overrides: { locateFile: (path: string, prefix: string) => (path.endsWith('.wasm') ? url : prefix + path) } });
    const options = { tryHarder: true, maxNumberOfSymbols: 4 };

    return async (frame) => {
        const image = frame instanceof Blob ? frame : still(frame);
        if (image === null) return [];

        return (await readBarcodes(image, options)).map((code) => code.text);
    };
}

/** The live picture's current frame, or null before it has one. */
function still(video: HTMLVideoElement): ImageData | null {
    const { videoWidth: width, videoHeight: height } = video;
    const context = width && height ? canvas(width, height).getContext('2d', { willReadFrequently: true }) : null;
    if (!context) return null;
    context.drawImage(video, 0, 0, width, height);

    return context.getImageData(0, 0, width, height);
}

function canvas(width: number, height: number): HTMLCanvasElement {
    const element = document.createElement('canvas');
    element.width = width;
    element.height = height;

    return element;
}

/** A product's code (EAN, UPC: all digits) before any other code in the picture. */
function pick(codes: string[]): string | null {
    return codes.find((code) => /^\d+$/.test(code)) ?? codes[0] ?? null;
}

/** PWA-031: the barcode in a photo, or null when there is none. */
export async function readBarcode(photo: Blob): Promise<string | null> {
    try {
        return pick(await (await decode())(photo));
    } catch {
        return null;
    }
}

/**
 * PWA-032: a photo as a JPEG data URL, its longest side at most 2048 px and its orientation applied, at the best
 * quality that fits the upload. null when the file is not a picture or will not fit.
 */
export async function prepareImage(photo: Blob): Promise<string | null> {
    let bitmap: ImageBitmap;
    try {
        bitmap = await createImageBitmap(photo, { imageOrientation: 'from-image' });
    } catch {
        return null;
    }
    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);
    const target = canvas(width, height);
    const context = target.getContext('2d');
    context?.drawImage(bitmap, 0, 0, width, height);
    bitmap.close();
    if (!context) return null;

    for (const quality of [0.85, 0.7, 0.5, 0.3]) {
        const url = target.toDataURL('image/jpeg', quality);
        if (url.length <= MAX_LENGTH) return url;
    }

    return null;
}

/**
 * PWA-031: the rear camera's live picture in a <video>, read for a barcode until one is found. `access` says
 * whether there is a camera to offer (PLT-003) and whether the browser refuses it (FOOD-040). The camera stops
 * when a code is found, on stop(), and when the screen goes.
 */
export function useCamera(): {
    access: Ref<CameraAccess>;
    live: Ref<boolean>;
    start: (video: HTMLVideoElement, onCode: (code: string) => void) => Promise<void>;
    stop: () => void;
} {
    const access = ref<CameraAccess>('none');
    const live = ref(false);
    let stream: MediaStream | null = null;
    let timer: ReturnType<typeof setTimeout> | undefined;
    // Each start gets a turn; a stop or a newer start ends the older one's reads.
    let turn = 0;

    void check().then((found) => (access.value = found));

    async function start(video: HTMLVideoElement, onCode: (code: string) => void): Promise<void> {
        stop();
        const mine = ++turn;
        try {
            const opened = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
            if (mine !== turn) return opened.getTracks().forEach((track) => track.stop());
            stream = opened;
            video.srcObject = opened;
            await video.play();
        } catch (error) {
            stop();
            const name = error instanceof DOMException ? error.name : '';
            if (name === 'NotAllowedError' || name === 'SecurityError') access.value = 'denied';
            else if (name === 'NotFoundError' || name === 'OverconstrainedError') access.value = 'none';
            else throw error;
            return;
        }
        live.value = true;
        const read = decode();
        const look = async (): Promise<void> => {
            const code = pick(await (await read)(video).catch(() => []));
            if (mine !== turn) return;
            if (code === null) {
                timer = setTimeout(look, READ_EVERY);
                return;
            }
            stop();
            onCode(code);
        };
        timer = setTimeout(look, READ_EVERY);
    }

    function stop(): void {
        turn++;
        clearTimeout(timer);
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        live.value = false;
    }

    onScopeDispose(stop);

    return { access, live, start, stop };
}

/** Whether the device has a camera, and whether the browser already refuses it. */
async function check(): Promise<CameraAccess> {
    const media = navigator.mediaDevices;
    if (!media?.getUserMedia) return 'none';
    const devices = await media.enumerateDevices().catch(() => []);
    if (!devices.some((device) => device.kind === 'videoinput')) return 'none';
    const state = await navigator.permissions
        ?.query({ name: 'camera' as PermissionName })
        .then((status) => status.state)
        .catch(() => 'prompt');

    return state === 'denied' ? 'denied' : 'ready';
}
