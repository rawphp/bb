import { ref } from 'vue';
import { now } from './clock';

// The install offer (PWA-003). Nothing calls offerInstall() yet: the end of onboarding
// (V_ONB2) or Today (V_TODAY1) is to call it once the user is through onboarding,
// from a tap, since Chrome shows its prompt only on a user gesture. Until then no
// install offer is made, Chrome's own included. Android gets Chrome's prompt; iPhone
// gets a sheet showing Share, then Add to Home Screen. Dismissed means not offered
// again for 30 days.

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
        // this once, early in the page's life, so it is caught here at start-up.
        event.preventDefault();
        chromePrompt = event as InstallPromptEvent;
    });
    window.addEventListener('appinstalled', () => (chromePrompt = null));
}

export async function offerInstall(): Promise<void> {
    if (installed() || snoozed()) return;
    const agent = navigator.userAgent;

    if (/Android/.test(agent) && chromePrompt) {
        const event = chromePrompt;
        chromePrompt = null;
        await event.prompt();
        if ((await event.userChoice).outcome === 'dismissed') snooze();
    } else if (/iPhone/.test(agent)) {
        installSheet.value = true;
    }
}

export function dismissInstall(): void {
    installSheet.value = false;
    snooze();
}

function installed(): boolean {
    return matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true;
}

function snoozed(): boolean {
    return now().getTime() < Number(localStorage.getItem(KEY));
}

function snooze(): void {
    localStorage.setItem(KEY, String(now().getTime() + SNOOZE_MS));
}
