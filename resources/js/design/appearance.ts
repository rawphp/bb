// Light, dark or follow the system (SET-002, PLT-005). A device setting, kept in
// local storage like rp-clone's UserDefaults. tokens.css reads data-appearance,
// so a change repaints at once (PWA-041).

export type Appearance = 'system' | 'light' | 'dark';

const KEY = 'appearance';

export function storedAppearance(): Appearance {
    const value = localStorage.getItem(KEY);

    return value === 'light' || value === 'dark' ? value : 'system';
}

export function setAppearance(appearance: Appearance): void {
    if (appearance === 'system') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, appearance);

    applyStoredAppearance();
}

export function applyStoredAppearance(): void {
    const appearance = storedAppearance();
    const root = document.documentElement;

    if (appearance === 'system') delete root.dataset.appearance;
    else root.dataset.appearance = appearance;
}
