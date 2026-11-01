import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { applyStoredAppearance, setAppearance, storedAppearance } from './appearance';

const root = document.documentElement;

// The page head sets the stored appearance before the first paint (app.blade.php).
const layout = readFileSync(join(import.meta.dirname, '../../views/app.blade.php'), 'utf8');
const headScript = layout.match(/<script>([\s\S]*?)<\/script>/)?.[1] ?? '';

describe('appearance', () => {
    afterEach(() => {
        vi.restoreAllMocks();
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

    it('SET-002 blocked site storage follows the system and still switches the page', () => {
        vi.spyOn(window, 'localStorage', 'get').mockImplementation(() => {
            throw new DOMException('The operation is insecure.', 'SecurityError');
        });

        expect(storedAppearance()).toBe('system');
        expect(() => applyStoredAppearance()).not.toThrow();
        expect(root.dataset.appearance).toBeUndefined();

        setAppearance('dark');
        expect(root.dataset.appearance).toBe('dark');

        setAppearance('system');
        expect(root.dataset.appearance).toBeUndefined();
    });

    it('PLT-005 the page head applies the stored override before the app script loads', () => {
        expect(layout.indexOf(headScript)).toBeLessThan(layout.indexOf('@vite'));

        localStorage.setItem('appearance', 'dark');
        new Function(headScript)();
        expect(root.dataset.appearance).toBe('dark');

        delete root.dataset.appearance;
        localStorage.setItem('appearance', 'sepia');
        new Function(headScript)();
        expect(root.dataset.appearance).toBeUndefined();
    });

    it('PLT-005 the page head script survives blocked site storage', () => {
        vi.spyOn(window, 'localStorage', 'get').mockImplementation(() => {
            throw new DOMException('The operation is insecure.', 'SecurityError');
        });

        expect(() => new Function(headScript)()).not.toThrow();
        expect(root.dataset.appearance).toBeUndefined();
    });
});
