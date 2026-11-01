import { afterEach, describe, expect, it } from 'vitest';
import { applyStoredAppearance, setAppearance, storedAppearance } from './appearance';

const root = document.documentElement;

describe('appearance', () => {
    afterEach(() => {
        localStorage.clear();
        delete root.dataset.appearance;
    });

    it('SET-002 follows the system when nothing is stored', () => {
        expect(storedAppearance()).toBe('system');
        applyStoredAppearance();
        expect(root.dataset.appearance).toBeUndefined();
    });

    it.each(['light', 'dark'] as const)('SET-002 PWA-041 stores %s and switches the page without a reload', (appearance) => {
        setAppearance(appearance);

        expect(localStorage.getItem('appearance')).toBe(appearance);
        expect(root.dataset.appearance).toBe(appearance);
        expect(storedAppearance()).toBe(appearance);
    });

    it('SET-002 choosing system drops the override', () => {
        setAppearance('dark');
        setAppearance('system');

        expect(localStorage.getItem('appearance')).toBeNull();
        expect(root.dataset.appearance).toBeUndefined();
    });

    it('PLT-005 applies the stored override when the app starts', () => {
        localStorage.setItem('appearance', 'dark');
        applyStoredAppearance();

        expect(root.dataset.appearance).toBe('dark');
    });

    it('SET-002 treats an unknown stored value as system', () => {
        localStorage.setItem('appearance', 'sepia');

        expect(storedAppearance()).toBe('system');
        applyStoredAppearance();
        expect(root.dataset.appearance).toBeUndefined();
    });
});
