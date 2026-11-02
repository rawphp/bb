import { ref } from 'vue';
import { now } from './clock';
import { isStandalone } from './device';

// The install offer (PWA-003). Android gets Chrome's prompt; iPhone gets a sheet showing
// Share, then Add to Home Screen. Dismissed means not offered again for 30 days.
//
// Nothing offers it yet. The screen that does (the end of onboarding, V_ONB2, or Today,
// V_TODAY1) wires both halves in the same change: listenForInstall() in main.ts at
// start-up, and offerInstall() from a tap once the user is through onboarding, since
// Chrome shows its prompt only on a user gesture. Until then Chrome's own install
// mini-infobar is left alone. pwaWiring.test.ts enforces both directions.

type InstallPromptEvent = Event & {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

const KEY = 'bb.install-snoozed-until';
const SNOOZE_MS = 30 * 86_400_000;

let chromePrompt: InstallPromptEvent | null = null;

export const installSheet = ref(false);

export function listenForInstall(): void {
    window.addEventListener('beforeinstallprompt', (event) => {
        // Keep Chrome's own mini-bar away; the offer comes after onboarding. Chrome fires
        // this once, early in the page's life, which is why this runs at start-up.
        event.preventDefault();
        chromePrompt = event as InstallPromptEvent;
    });
    window.addEventListener('appinstalled', () => (chromePrompt = null));
}

export async function offerInstall(): Promise<void> {
    if (isStandalone() || snoozed()) return;
    const agent = navigator.userAgent;

    if (/Android/.test(agent) && chromePrompt) {
        const event = chromePrompt;
        chromePrompt = null;
        await event.prompt();
        if ((await event.userChoice).outcome === 'dismissed') snooze();
    } else if (/iPhone/.test(agent)) {
        // PWA-003 offers the install on a phone, so iPhone only; an iPad keeps Safari's own menu.
        installSheet.value = true;
    }
}

export function dismissInstall(): void {
    installSheet.value = false;
    snooze();
}

function snoozed(): boolean {
    return now().getTime() < Number(localStorage.getItem(KEY));
}

function snooze(): void {
    localStorage.setItem(KEY, String(now().getTime() + SNOOZE_MS));
}
