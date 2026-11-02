import { onScopeDispose, ref, type Ref } from 'vue';

// The scanner's camera and photos (FOOD-040, PWA-031, PWA-032, PLT-003), ported from rp-clone's ScanImages.swift.
// Barcode mode reads codes from the rear camera's live picture: with the browser's BarcodeDetector where it has
// one, and zxing-wasm where it doesn't (iOS Safari). Label and photo modes upload a still photo, shrunk first.

/** ready: there is a camera to ask for; denied: the browser refuses it; none: the device has no camera. */
export type CameraAccess = 'ready' | 'denied' | 'none';

/** The barcode reader could not load (zxing-wasm offline), as distinct from a picture with no code in it. */
export class ReaderUnavailable extends Error {
    constructor(options?: { cause?: unknown }) {
        super('The barcode reader could not load', options);
        this.name = 'ReaderUnavailable';
    }
}

/** The longest side of an uploaded photo, in pixels. */
const MAX_SIDE = 2048;
/** The longest side of a live frame zxing reads, in pixels. */
const FRAME_SIDE = 1280;
/** scan_photo and scan_label take a data URL of up to 2,000,000 characters. */
const MAX_LENGTH = 2_000_000;
/** How often the live picture is read, in milliseconds. */
const READ_EVERY = 250;
/** The codes printed on food packs, as BarcodeDetector and zxing name them. Others, such as QR codes, are skipped. */
const RETAIL = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf'];
const ZXING_RETAIL = ['EAN13', 'EAN8', 'UPCA', 'UPCE', 'ITF'] as const;
/** What food_barcode_lookup takes: 1 to 14 digits. */
const PRODUCT_CODE = /^\d{1,14}$/;

type Frame = HTMLVideoElement | Blob;
type Decode = (frame: Frame) => Promise<string[]>;

interface Detector {
    detect(source: ImageBitmapSource): Promise<{ rawValue: string }[]>;
}
interface DetectorClass {
    new (options: { formats: string[] }): Detector;
    getSupportedFormats(): Promise<string[]>;
}

let wasm: Promise<Decode> | undefined;

/**
 * The browser's BarcodeDetector when it reads a retail format, else zxing-wasm, loaded on first use. A zxing load
 * that fails rejects with ReaderUnavailable and is tried again next time.
 */
async function decoder(): Promise<Decode> {
    const native = await nativeDecoder();
    if (native) return native;
    wasm ??= wasmDecoder().catch((error: unknown) => {
        wasm = undefined;
        throw new ReaderUnavailable({ cause: error });
    });

    return wasm;
}

async function nativeDecoder(): Promise<Decode | null> {
    const Native = (globalThis as { BarcodeDetector?: DetectorClass }).BarcodeDetector;
    const supported = (await Native?.getSupportedFormats().catch(() => [])) ?? [];
    const formats = RETAIL.filter((format) => supported.includes(format));
    if (!Native || formats.length === 0) return null;
    const detector = new Native({ formats });

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
    const [{ prepareZXingModule, purgeZXingModule, readBarcodes }, { default: url }] = await Promise.all([
        import('zxing-wasm/reader'),
        import('zxing-wasm/reader/zxing_reader.wasm?url'),
    ]);
    try {
        // The wasm ships with the app, not from a CDN. Loaded now, so a failure shows here and not as "no code".
        await prepareZXingModule({ overrides: { locateFile: (path: string, prefix: string) => (path.endsWith('.wasm') ? url : prefix + path) }, fireImmediately: true });
    } catch (error) {
        // zxing keeps a failed load; drop it so the next try fetches again.
        purgeZXingModule();
        throw error;
    }
    const options = { formats: [...ZXING_RETAIL], tryHarder: true, maxNumberOfSymbols: 4 };
    // One canvas for every live frame.
    const target = document.createElement('canvas');
    let context: CanvasRenderingContext2D | null = null;

    /** The live picture's current frame, at most FRAME_SIDE on its longest side, or null before it has one. */
    function still(video: HTMLVideoElement): ImageData | null {
        const { videoWidth, videoHeight } = video;
        if (!videoWidth || !videoHeight) return null;
        const scale = Math.min(1, FRAME_SIDE / Math.max(videoWidth, videoHeight));
        const width = Math.round(videoWidth * scale);
        const height = Math.round(videoHeight * scale);
        if (target.width !== width || target.height !== height) Object.assign(target, { width, height });
        context ??= target.getContext('2d', { willReadFrequently: true });
        if (!context) return null;
        context.drawImage(video, 0, 0, width, height);

        return context.getImageData(0, 0, width, height);
    }

    return async (frame) => {
        const image = frame instanceof Blob ? frame : still(frame);
        if (image === null) return [];

        return (await readBarcodes(image, options)).map((code) => code.text);
    };
}

function canvas(width: number, height: number): HTMLCanvasElement {
    const element = document.createElement('canvas');
    element.width = width;
    element.height = height;

    return element;
}

/** The first product code in the picture, or null. */
function pick(codes: string[]): string | null {
    return codes.find((code) => PRODUCT_CODE.test(code)) ?? null;
}

/** PWA-031: the product code in a photo, or null when there is none. Rejects with ReaderUnavailable. */
export async function readBarcode(photo: Blob): Promise<string | null> {
    const decode = await decoder();

    return pick(await decode(photo).catch(() => []));
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

    /** Rejects with ReaderUnavailable when there is no reader, and with the browser's error for an odd failure. */
    async function start(video: HTMLVideoElement, onCode: (code: string) => void): Promise<void> {
        stop();
        const mine = ++turn;
        let decode: Decode;
        try {
            decode = await decoder();
            if (mine !== turn) return;
            const opened = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
            if (mine !== turn) return opened.getTracks().forEach((track) => track.stop());
            stream = opened;
            video.srcObject = opened;
            await video.play();
        } catch (error) {
            // A newer start or a stop took over while this one waited: it ends quietly.
            if (mine !== turn) return;
            stop();
            const name = error instanceof DOMException ? error.name : '';
            if (name === 'NotAllowedError' || name === 'SecurityError') access.value = 'denied';
            else if (name === 'NotFoundError' || name === 'OverconstrainedError') access.value = 'none';
            else throw error;
            return;
        }
        if (mine !== turn) return;
        live.value = true;
        const look = async (): Promise<void> => {
            const code = pick(await decode(video).catch(() => []));
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
