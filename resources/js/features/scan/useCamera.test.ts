import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { prepareImage, readBarcode, useCamera } from './useCamera';

const zxing = vi.hoisted(() => ({ readBarcodes: vi.fn(), prepareZXingModule: vi.fn() }));
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
    static getSupportedFormats = vi.fn().mockResolvedValue(['ean_13', 'qr_code']);
    static found: { rawValue: string }[] = [];
    detect = vi.fn(async () => FakeDetector.found);
}

function inScope<T>(run: () => T): T {
    return effectScope().run(run)!;
}

beforeEach(() => {
    vi.useFakeTimers();
    track.stop.mockReset();
    zxing.readBarcodes.mockReset().mockResolvedValue([]);
    FakeDetector.found = [];
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

        FakeDetector.found = [{ rawValue: '5000159484695' }];
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
        await camera.start(document.createElement('video'), vi.fn());

        scope.stop();

        expect(track.stop).toHaveBeenCalled();
        expect(camera.live.value).toBe(false);
    });
});

describe('readBarcode', () => {
    it('PWA-031 reads a photo with zxing-wasm where the browser has no BarcodeDetector, digits first', async () => {
        zxing.readBarcodes.mockResolvedValue([{ text: 'https://example.com' }, { text: '0123456789012' }]);
        const photo = new Blob(['jpeg'], { type: 'image/jpeg' });

        await expect(readBarcode(photo)).resolves.toBe('0123456789012');
        expect(zxing.readBarcodes).toHaveBeenCalledWith(photo, expect.anything());
    });

    it('PWA-031 answers null for a photo with no barcode in it', async () => {
        await expect(readBarcode(new Blob(['jpeg']))).resolves.toBeNull();
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

    it('PWA-032 answers null for a file that is not a picture', async () => {
        vi.stubGlobal('createImageBitmap', vi.fn().mockRejectedValue(new Error('not an image')));

        await expect(prepareImage(new Blob(['text']))).resolves.toBeNull();
    });
});
