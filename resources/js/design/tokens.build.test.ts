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
        expect(css).toMatch(/@media \(prefers-color-scheme:dark\)\{:root\{--lightningcss-light: ;--lightningcss-dark:initial/);
        expect(css).toMatch(/:root\[data-appearance=light\]\{--lightningcss-light:initial;--lightningcss-dark: ;/);
        expect(css).toMatch(/:root\[data-appearance=dark\]\{--lightningcss-light: ;--lightningcss-dark:initial;/);
    });
});
