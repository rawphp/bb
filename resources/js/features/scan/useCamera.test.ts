import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { prepareImage, readBarcode, ReaderUnavailable, useCamera } from './useCamera';

const zxing = vi.hoisted(() => ({ readBarcodes: vi.fn(), prepareZXingModule: vi.fn(), purgeZXingModule: vi.fn() }));
vi.mock('zxing-wasm/reader', () => zxing);
vi.mock('zxing-wasm/reader/zxing_reader.wasm?url', () => ({ default: '/build/zxing_reader.wasm' }));

const track = { stop: vi.fn() };
const stream = { getTracks: () => [track] } as unknown as MediaStream;

function devices(kinds: MediaDeviceKind[], getUserMedia = vi.fn().mockResolvedValue(stream)) {
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { enumerateDevices: vi.fn().mockResolvedValue(kinds.map((kind) => ({ kind }))), getUserMedia },
    });

    return getUserMedia;
}

class FakeDetector {
    static getSupportedFormats = vi.fn().mockResolvedValue(['ean_13', 'qr_code', 'upc_a']);
    static found: { rawValue: string }[] = [];
    static options: unknown;
    /** A read the test answers itself, taken by the next detect(). */
    static pending: Promise<{ rawValue: string }[]> | null = null;
    constructor(options?: unknown) {
        FakeDetector.options = options;
    }
    detect = vi.fn(async () => {
        const held = FakeDetector.pending;
        FakeDetector.pending = null;
        return held ?? FakeDetector.found;
    });
}

function inScope<T>(run: () => T): T {
    return effectScope().run(run)!;
}

beforeEach(() => {
    vi.useFakeTimers();
    track.stop.mockReset();
    zxing.readBarcodes.mockReset().mockResolvedValue([]);
    zxing.prepareZXingModule.mockReset().mockResolvedValue({});
    zxing.purgeZXingModule.mockReset();
    FakeDetector.found = [];
    FakeDetector.pending = null;
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('useCamera', () => {
    it('PLT-003 says there is no camera on a device without one', async () => {
        devices(['audioinput']);
        const camera = inScope(() => useCamera());

        await flushPromises();

        expect(camera.access.value).toBe('none');
    });

    it('PWA-031 reads a barcode from the rear camera\'s live picture with BarcodeDetector', async () => {
        vi.stubGlobal('BarcodeDetector', FakeDetector);
        const getUserMedia = devices(['videoinput']);
        const camera = inScope(() => useCamera());
        const video = document.createElement('video');
        const onCode = vi.fn();
        await flushPromises();

        expect(camera.access.value).toBe('ready');

        await camera.start(video, onCode);

        expect(getUserMedia).toHaveBeenCalledWith({ audio: false, video: { facingMode: { ideal: 'environment' } } });
        expect(video.srcObject).toBe(stream);
        expect(camera.live.value).toBe(true);

        expect(FakeDetector.options).toEqual({ formats: ['ean_13', 'upc_a'] });

        // A QR code on the pack is not a product code: the camera keeps reading.
        FakeDetector.found = [{ rawValue: 'https://id.gs1.org/01/09506000134352' }];
        await vi.advanceTimersByTimeAsync(500);
        expect(onCode).not.toHaveBeenCalled();
        expect(camera.live.value).toBe(true);

        FakeDetector.found = [{ rawValue: 'https://example.com' }, { rawValue: '5000159484695' }];
        await vi.advanceTimersByTimeAsync(500);

        expect(onCode).toHaveBeenCalledExactlyOnceWith('5000159484695');
        expect(camera.live.value).toBe(false);
        expect(track.stop).toHaveBeenCalled();
    });

    it('FOOD-040 knows the camera is turned off when the browser refuses it', async () => {
        devices(['videoinput'], vi.fn().mockRejectedValue(new DOMException('No', 'NotAllowedError')));
        const camera = inScope(() => useCamera());
        await flushPromises();

        await camera.start(document.createElement('video'), vi.fn());

        expect(camera.access.value).toBe('denied');
        expect(camera.live.value).toBe(false);
    });

    it('PWA-031 stops the camera when the screen goes', async () => {
        vi.stubGlobal('BarcodeDetector', FakeDetector);
        devices(['videoinput']);
        const scope = effectScope();
        const camera = scope.run(() => useCamera())!;
        await flushPromises();
        const onCode = vi.fn();
        await camera.start(document.createElement('video'), onCode);

        scope.stop();
        FakeDetector.found = [{ rawValue: '5000159484695' }];
        await vi.advanceTimersByTimeAsync(1000);

        expect(track.stop).toHaveBeenCalled();
        expect(camera.live.value).toBe(false);
        expect(onCode).not.toHaveBeenCalled();
    });

    it('PWA-031 drops a code read after the camera was stopped', async () => {
        vi.stubGlobal('BarcodeDetector', FakeDetector);
        devices(['videoinput']);
        let answer!: (codes: { rawValue: string }[]) => void;
        const camera = inScope(() => useCamera());
        await flushPromises();
        const onCode = vi.fn();
        await camera.start(document.createElement('video'), onCode);
        // The next read is held until the test answers it.
        FakeDetector.pending = new Promise((resolve) => (answer = resolve));

        await vi.advanceTimersByTimeAsync(250);
        camera.stop();
        answer([{ rawValue: '5000159484695' }]);
        await vi.advanceTimersByTimeAsync(500);

        expect(onCode).not.toHaveBeenCalled();
    });

    it('PWA-031 opens no camera when it is stopped while the reader loads', async () => {
        let formats!: (value: string[]) => void;
        vi.stubGlobal('BarcodeDetector', class extends FakeDetector {
            static override getSupportedFormats = vi.fn(() => new Promise<string[]>((resolve) => (formats = resolve)));
        });
        const getUserMedia = devices(['videoinput']);
        const camera = inScope(() => useCamera());
        await flushPromises();

        const starting = camera.start(document.createElement('video'), vi.fn());
        camera.stop();
        formats(['ean_13']);
        await starting;

        expect(getUserMedia).not.toHaveBeenCalled();
        expect(camera.live.value).toBe(false);
    });

    it('PWA-031 keeps a newer camera on when an older start fails late', async () => {
        vi.stubGlobal('BarcodeDetector', FakeDetector);
        let refuseLate!: (error: unknown) => void;
        devices(['videoinput'], vi.fn()
            .mockReturnValueOnce(new Promise<MediaStream>((_, reject) => (refuseLate = reject)))
            .mockResolvedValueOnce(stream));
        const camera = inScope(() => useCamera());
        await flushPromises();

        const starting = camera.start(document.createElement('video'), vi.fn());
        await flushPromises();
        await camera.start(document.createElement('video'), vi.fn());
        refuseLate(new DOMException('No', 'NotAllowedError'));
        await starting;

        expect(camera.live.value).toBe(true);
        expect(camera.access.value).toBe('ready');
        expect(track.stop).not.toHaveBeenCalled();
    });

    it('PWA-031 reads the live picture with zxing-wasm, a frame at most 1280 px wide', async () => {
        vi.resetModules();
        const fresh = await import('./useCamera');
        devices(['videoinput']);
        const camera = inScope(() => fresh.useCamera());
        await flushPromises();
        const video = document.createElement('video');
        Object.defineProperties(video, { videoWidth: { value: 1920 }, videoHeight: { value: 1080 } });
        const frame = { data: new Uint8ClampedArray(4) } as unknown as ImageData;
        const drawImage = vi.fn();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage, getImageData: () => frame } as unknown as CanvasRenderingContext2D);
        zxing.readBarcodes.mockResolvedValue([{ text: '5000159484695' }]);
        const onCode = vi.fn();

        await camera.start(video, onCode);
        await vi.advanceTimersByTimeAsync(250);

        expect(drawImage).toHaveBeenCalledWith(video, 0, 0, 1280, 720);
        expect(zxing.readBarcodes).toHaveBeenCalledWith(frame, expect.anything());
        expect(onCode).toHaveBeenCalledWith('5000159484695');
    });

    it('PWA-031 rejects a live start when the zxing reader cannot load, and opens no camera', async () => {
        vi.resetModules();
        const fresh = await import('./useCamera');
        const getUserMedia = devices(['videoinput']);
        zxing.prepareZXingModule.mockRejectedValueOnce(new Error('offline'));
        const camera = inScope(() => fresh.useCamera());
        await flushPromises();

        await expect(camera.start(document.createElement('video'), vi.fn())).rejects.toBeInstanceOf(fresh.ReaderUnavailable);
        expect(getUserMedia).not.toHaveBeenCalled();
        expect(camera.live.value).toBe(false);
    });

    it('PWA-031 drops a camera that opens after a newer start, and reads only for the newer one', async () => {
        vi.stubGlobal('BarcodeDetector', FakeDetector);
        const late = { stop: vi.fn() };
        let openLate!: (value: MediaStream) => void;
        devices(['videoinput'], vi.fn()
            .mockReturnValueOnce(new Promise<MediaStream>((resolve) => (openLate = resolve)))
            .mockResolvedValueOnce(stream));
        const camera = inScope(() => useCamera());
        await flushPromises();
        const first = vi.fn();
        const second = vi.fn();

        const starting = camera.start(document.createElement('video'), first);
        await flushPromises();
        await camera.start(document.createElement('video'), second);
        openLate({ getTracks: () => [late] } as unknown as MediaStream);
        await starting;
        FakeDetector.found = [{ rawValue: '5000159484695' }];
        await vi.advanceTimersByTimeAsync(500);

        expect(late.stop).toHaveBeenCalled();
        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledExactlyOnceWith('5000159484695');
    });

    it('PWA-031 stays off when it is stopped while the picture starts to play', async () => {
        vi.stubGlobal('BarcodeDetector', FakeDetector);
        devices(['videoinput']);
        let playing!: () => void;
        vi.mocked(HTMLMediaElement.prototype.play).mockReturnValue(new Promise<void>((resolve) => (playing = resolve)));
        const camera = inScope(() => useCamera());
        await flushPromises();

        const starting = camera.start(document.createElement('video'), vi.fn());
        await flushPromises();
        camera.stop();
        playing();
        await starting;

        expect(camera.live.value).toBe(false);
    });

    it('PLT-003 and FOOD-040 read no camera and a camera the browser already refuses', async () => {
        devices(['videoinput'], vi.fn().mockRejectedValue(new DOMException('None', 'NotFoundError')));
        const missing = inScope(() => useCamera());
        await flushPromises();
        await missing.start(document.createElement('video'), vi.fn());
        expect(missing.access.value).toBe('none');

        Object.defineProperty(navigator, 'permissions', { configurable: true, value: { query: vi.fn().mockResolvedValue({ state: 'denied' }) } });
        const refused = inScope(() => useCamera());
        await flushPromises();
        expect(refused.access.value).toBe('denied');
        Reflect.deleteProperty(navigator, 'permissions');
    });
});

describe('readBarcode', () => {
    it('PWA-031 says the reader is unavailable when zxing-wasm cannot load, and loads it again next time', async () => {
        vi.resetModules();
        const fresh = await import('./useCamera');
        zxing.prepareZXingModule.mockRejectedValueOnce(new Error('offline'));
        zxing.readBarcodes.mockResolvedValue([{ text: '5000159484695' }]);

        await expect(fresh.readBarcode(new Blob(['jpeg']))).rejects.toBeInstanceOf(fresh.ReaderUnavailable);
        expect(zxing.purgeZXingModule).toHaveBeenCalled();
        await expect(fresh.readBarcode(new Blob(['jpeg']))).resolves.toBe('5000159484695');
    });

    it('PWA-031 reads a photo with zxing-wasm where the browser has no BarcodeDetector, a product code only', async () => {
        zxing.readBarcodes.mockResolvedValue([{ text: 'https://example.com' }, { text: '0123456789012' }]);
        const photo = new Blob(['jpeg'], { type: 'image/jpeg' });

        await expect(readBarcode(photo)).resolves.toBe('0123456789012');
        expect(zxing.readBarcodes).toHaveBeenCalledWith(photo, expect.objectContaining({ formats: ['EAN13', 'EAN8', 'UPCA', 'UPCE', 'ITF'] }));
    });

    it('PWA-031 loads the zxing wasm from the app, not a CDN', async () => {
        vi.resetModules();
        const fresh = await import('./useCamera');
        await fresh.readBarcode(new Blob(['jpeg']));

        const { overrides } = zxing.prepareZXingModule.mock.calls[0]![0] as { overrides: { locateFile: (path: string, prefix: string) => string } };
        expect(overrides.locateFile('zxing_reader.wasm', 'https://cdn.example/')).toBe('/build/zxing_reader.wasm');
    });

    it('PWA-031 answers null for a photo with no product code in it', async () => {
        await expect(readBarcode(new Blob(['jpeg']))).resolves.toBeNull();
        zxing.readBarcodes.mockResolvedValue([{ text: 'https://id.gs1.org/01/09506000134352' }]);
        await expect(readBarcode(new Blob(['jpeg']))).resolves.toBeNull();
        // Longer than the 14 digits food_barcode_lookup takes.
        zxing.readBarcodes.mockResolvedValue([{ text: '123456789012345' }]);
        await expect(readBarcode(new Blob(['jpeg']))).resolves.toBeNull();
    });

    it('PWA-031 keeps why the reader could not load', () => {
        const why = new Error('offline');
        const failure = new ReaderUnavailable({ cause: why });

        expect(failure.name).toBe('ReaderUnavailable');
        expect(failure.cause).toBe(why);
    });

    it('PWA-031 reads with zxing-wasm where BarcodeDetector reads no retail format', async () => {
        vi.stubGlobal('BarcodeDetector', class extends FakeDetector {
            static override getSupportedFormats = vi.fn().mockResolvedValue(['qr_code']);
        });
        zxing.readBarcodes.mockResolvedValue([{ text: '5000159484695' }]);

        await expect(readBarcode(new Blob(['jpeg']))).resolves.toBe('5000159484695');
        expect(zxing.readBarcodes).toHaveBeenCalled();
    });
});

describe('prepareImage', () => {
    it('PWA-032 shrinks a photo to 2048 px on its longest side, as a JPEG data URL', async () => {
        const close = vi.fn();
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 4032, height: 3024, close }));
        const drawImage = vi.fn();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage } as unknown as CanvasRenderingContext2D);
        const toDataURL = vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockReturnValue('data:image/jpeg;base64,AAAA');

        await expect(prepareImage(new Blob(['jpeg']))).resolves.toBe('data:image/jpeg;base64,AAAA');
        expect(drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 2048, 1536);
        expect(toDataURL).toHaveBeenCalledWith('image/jpeg', 0.85);
        expect(close).toHaveBeenCalled();
    });

    it('PWA-032 lowers the quality until the photo fits the upload, and gives up past the lowest', async () => {
        const close = vi.fn();
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 3024, height: 4032, close }));
        const drawImage = vi.fn();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage } as unknown as CanvasRenderingContext2D);
        const toDataURL = vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockImplementation((_type, quality) => (quality === 0.7 ? 'data:fits' : 'x'.repeat(2_000_001)));

        await expect(prepareImage(new Blob(['jpeg']))).resolves.toBe('data:fits');
        expect(drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 1536, 2048);

        toDataURL.mockReturnValue('x'.repeat(2_000_001));
        await expect(prepareImage(new Blob(['jpeg']))).resolves.toBeNull();
    });

    it('PWA-032 never enlarges a small photo', async () => {
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 800, height: 600, close: vi.fn() }));
        const drawImage = vi.fn();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage } as unknown as CanvasRenderingContext2D);
        vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockReturnValue('data:small');

        await expect(prepareImage(new Blob(['jpeg']))).resolves.toBe('data:small');
        expect(drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 800, 600);
    });

    it('PWA-032 answers null for a file that is not a picture', async () => {
        vi.stubGlobal('createImageBitmap', vi.fn().mockRejectedValue(new Error('not an image')));

        await expect(prepareImage(new Blob(['text']))).resolves.toBeNull();
    });
});
