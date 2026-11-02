import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

// Read from disk: Vitest blanks CSS imports, ?raw included.
const css = readFileSync(join(import.meta.dirname, 'tokens.css'), 'utf8');

type Pair = { light: string; dark: string };

// rp-clone at 2aa1edeb, copied by hand: ColorToken.swift (DesignTokens), Macro.swift
// and StatusTone.swift. Each one must have a CSS variable with the same values.
const RP_CLONE: Record<string, Pair> = {
    background: { light: '#f2f2f7', dark: '#0b0b0f' },
    surface: { light: '#ffffff', dark: '#1c1c1e' },
    accent: { light: '#4b3fd1', dark: '#9c93ff' },
    'on-accent': { light: '#ffffff', dark: '#0b0b0f' },
    'primary-text': { light: '#000000', dark: '#ffffff' },
    'secondary-text': { light: '#48484a', dark: '#aeaeb2' },
    'macro-calories': { light: '#0050c8', dark: '#5aa2ff' },
    'macro-protein': { light: '#c4160f', dark: '#ff6b6b' },
    'macro-fat': { light: '#b34700', dark: '#ff9f43' },
    'macro-carbs': { light: '#1b7a2e', dark: '#4cd964' },
    'tone-good': { light: '#145c28', dark: '#8fe3a3' },
    'tone-good-bg': { light: '#d9f2df', dark: '#12361d' },
    'tone-neutral': { light: '#3a3a3c', dark: '#d1d1d6' },
    'tone-neutral-bg': { light: '#e5e5ea', dark: '#3a3a3c' },
    'tone-warning': { light: '#7a3e00', dark: '#ffc27a' },
    'tone-warning-bg': { light: '#ffe8cc', dark: '#4a2c00' },
    'tone-info': { light: '#0b4a8f', dark: '#9ccbff' },
    'tone-info-bg': { light: '#d6e8ff', dark: '#0f2f55' },
};

const MODES = ['light', 'dark'] as const;

function colours(): Record<string, Pair> {
    const found: Record<string, Pair> = {};
    for (const [, name, light, dark] of css.matchAll(/--bb-([a-z-]+):\s*light-dark\((#[0-9a-f]{6}),\s*(#[0-9a-f]{6})\);/gi)) {
        found[name!] = { light: light!.toLowerCase(), dark: dark!.toLowerCase() };
    }
    return found;
}

// WCAG 2.x, as rp-clone's SRGBColor.
function luminance(hex: string): number {
    const [r, g, b] = [1, 3, 5].map((i) => {
        const c = parseInt(hex.slice(i, i + 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    }) as [number, number, number];
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number];
    return (hi + 0.05) / (lo + 0.05);
}

describe('design tokens', () => {
    const tokens = colours();

    it('PWA-040 UI-011 every rp-clone colour token is a CSS variable with its light and dark value', () => {
        expect(tokens).toEqual(RP_CLONE);
    });

    it('PLT-005 PWA-041 colours follow the system and the stored appearance override', () => {
        expect(css).toMatch(/:root\s*\{[^}]*color-scheme:\s*light dark;/);
        expect(css).toMatch(/:root\[data-appearance='light'\]\s*\{\s*color-scheme:\s*light;\s*\}/);
        expect(css).toMatch(/:root\[data-appearance='dark'\]\s*\{\s*color-scheme:\s*dark;\s*\}/);
    });

    it('PWA-040 every token is a Tailwind utility through @theme inline', () => {
        const theme = css.match(/@theme inline\s*\{([^}]*)\}/)?.[1] ?? '';

        for (const key of Object.keys(RP_CLONE)) {
            expect(theme).toContain(`--color-${key}: var(--bb-${key});`);
        }
        expect(theme).toContain('--spacing-tap: var(--bb-min-tap);');
        expect(theme).toContain('--font-sans: var(--bb-font);');
    });

    it('PWA-040 the type stack is the system font', () => {
        expect(css).toMatch(/--bb-font:\s*-apple-system,\s*system-ui,/);
    });

    it('UI-031 the contrast formula matches the WCAG reference values', () => {
        expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 2);
        expect(contrast('#ffffff', '#ffffff')).toBeCloseTo(1, 3);
        expect(contrast('#777777', '#ffffff')).toBeCloseTo(4.48, 2);
    });

    const grounds = ['background', 'surface'];
    const onGround = ['primary-text', 'secondary-text', 'accent', 'macro-calories', 'macro-protein', 'macro-fat', 'macro-carbs'];
    const pairs: [string, string][] = [
        ...onGround.flatMap((text) => grounds.map((ground): [string, string] => [text, ground])),
        ['on-accent', 'accent'],
        ...['good', 'neutral', 'warning', 'info'].map((tone): [string, string] => [`tone-${tone}`, `tone-${tone}-bg`]),
    ];

    it.each(MODES.flatMap((mode) => pairs.map(([text, ground]) => [text, ground, mode] as const)))(
        'UI-031 %s on %s meets WCAG AA in %s mode',
        (text, ground, mode) => {
            expect(contrast(tokens[text]![mode], tokens[ground]![mode])).toBeGreaterThanOrEqual(4.5);
        },
    );
});
