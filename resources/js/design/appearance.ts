// Light, dark or follow the system (SET-002, PLT-005). A device setting, kept in
// local storage like rp-clone's UserDefaults. tokens.css reads data-appearance,
// so a change repaints at once (PWA-041). When the browser blocks site storage,
// the page follows the system and a choice lasts until the page closes.

export type Appearance = 'system' | 'light' | 'dark';

const KEY = 'appearance';

export function storedAppearance(): Appearance {
    let value: string | null = null;
    try {
        value = localStorage.getItem(KEY);
    } catch {
        // Storage blocked: follow the system.
    }

    return value === 'light' || value === 'dark' ? value : 'system';
}

export function setAppearance(appearance: Appearance): void {
    try {
        if (appearance === 'system') localStorage.removeItem(KEY);
        else localStorage.setItem(KEY, appearance);
    } catch {
        // Storage blocked: the choice still applies to this page.
    }

    apply(appearance);
}

export function applyStoredAppearance(): void {
    apply(storedAppearance());
}

function apply(appearance: Appearance): void {
    const root = document.documentElement;

    if (appearance === 'system') delete root.dataset.appearance;
    else root.dataset.appearance = appearance;
}
