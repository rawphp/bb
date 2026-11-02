import { beforeEach, describe, expect, it, vi } from 'vitest';

const ANDROID = 'Mozilla/5.0 (Linux; Android 15; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0 Safari/537.36';
const DAY_MS = 86_400_000;

function browser(agent: string, standalone = false) {
    Object.defineProperty(navigator, 'userAgent', { value: agent, configurable: true });
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: standalone && query === '(display-mode: standalone)' }));
}

function chromePrompt(outcome: 'accepted' | 'dismissed') {
    const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
        prompt: vi.fn(async () => undefined),
        userChoice: Promise.resolve({ outcome, platform: 'web' }),
    });
    window.dispatchEvent(event);

    return event;
}

async function load() {
    vi.resetModules();
    const install = await import('./install');
    install.listenForInstall();

    return install;
}

describe('install', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it("PWA-003 on Android, holds Chrome's prompt and shows it when offered", async () => {
        browser(ANDROID);
        const { offerInstall, installSheet } = await load();
        const event = chromePrompt('accepted');

        expect(event.defaultPrevented).toBe(true);
        await offerInstall();

        expect(event.prompt).toHaveBeenCalledTimes(1);
        expect(installSheet.value).toBe(false);
    });

    it('PWA-003 on iPhone, opens the Share then Add to Home Screen sheet', async () => {
        browser(IPHONE);
        const { offerInstall, installSheet } = await load();

        await offerInstall();

        expect(installSheet.value).toBe(true);
    });

    it('PWA-003 offers nothing in the installed app or on a desktop', async () => {
        browser(IPHONE, true);
        let install = await load();
        await install.offerInstall();
        expect(install.installSheet.value).toBe(false);

        browser(DESKTOP);
        install = await load();
        const event = chromePrompt('accepted');
        await install.offerInstall();
        expect(event.prompt).not.toHaveBeenCalled();
    });

    it("PWA-003 dismissing the iPhone sheet snoozes the offer for 30 days", async () => {
        vi.useFakeTimers({ now: new Date('2026-11-02T09:00:00Z'), toFake: ['Date'] });
        browser(IPHONE);
        const { offerInstall, dismissInstall, installSheet } = await load();
        await offerInstall();

        dismissInstall();
        expect(installSheet.value).toBe(false);

        vi.setSystemTime(Date.now() + 29 * DAY_MS);
        await offerInstall();
        expect(installSheet.value).toBe(false);

        vi.setSystemTime(Date.now() + 2 * DAY_MS);
        await offerInstall();
        expect(installSheet.value).toBe(true);
    });

    it("PWA-003 dismissing Chrome's prompt snoozes the offer for 30 days", async () => {
        browser(ANDROID);
        const install = await load();
        chromePrompt('dismissed');
        await install.offerInstall();

        const next = chromePrompt('accepted');
        await install.offerInstall();

        expect(next.prompt).not.toHaveBeenCalled();
    });
});
