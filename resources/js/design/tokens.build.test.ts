// @vitest-environment node
import tailwindcss from '@tailwindcss/vite';
import { build, type Rolldown } from 'vite';
import { describe, expect, it } from 'vitest';

// The CSS the app ships: app.css through Tailwind and Vite's production build.
async function builtCss(): Promise<string> {
    const result = (await build({
        configFile: false,
        logLevel: 'silent',
        root: process.cwd(),
        plugins: [tailwindcss()],
        build: { write: false, rollupOptions: { input: 'resources/css/app.css' } },
    })) as Rolldown.RolldownOutput;

    const css = result.output.find((file) => file.fileName.endsWith('.css'));
    return css?.type === 'asset' ? String(css.source) : '';
}

describe('built design tokens', () => {
    it('PLT-005 PWA-041 the shipped CSS lowers light-dark() so browsers without it keep the colours and the switch', async () => {
        const css = await builtCss();

        expect(css).toContain('--bb-background:var(--lightningcss-light,#f2f2f7)var(--lightningcss-dark,#0b0b0f)');
        expect(css).not.toContain('light-dark(');
        expect(css).toMatch(/@media \(prefers-color-scheme:dark\)\{:root(,\.bb-light)?\{--lightningcss-light: ;--lightningcss-dark:initial/);
        expect(css).toMatch(/:root\[data-appearance=light\]\{--lightningcss-light:initial;--lightningcss-dark: ;/);
        expect(css).toMatch(/:root\[data-appearance=dark\]\{--lightningcss-light: ;--lightningcss-dark:initial;/);
    });

    it('PWA-034 an element with bb-light keeps the light palette in a dark page, so the shared progress picture is light', async () => {
        const css = await builtCss();

        // The tokens are declared again on .bb-light, so they resolve against its own switch, not :root's.
        expect(css).toMatch(/:root,\.bb-light\{[^}]*--bb-surface:var\(--lightningcss-light,#fff\)var\(--lightningcss-dark,#1c1c1e\)/);
        expect(css).toMatch(/\.bb-light\{--lightningcss-light:initial;--lightningcss-dark: ;/);
        // Same specificity as the dark media rule, so .bb-light's light switch must come after it to win.
        const darkMedia = css.search(/@media \(prefers-color-scheme:dark\)\{[^}]*\.bb-light/);
        const lightSwitch = css.search(/(^|\})\.bb-light\{--lightningcss-light:initial;--lightningcss-dark: ;/);
        expect(darkMedia).toBeGreaterThan(-1);
        expect(lightSwitch).toBeGreaterThan(darkMedia);
    });
});
