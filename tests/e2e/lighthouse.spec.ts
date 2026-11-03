import { chromium, expect, test, type BrowserContext } from '@playwright/test';
import lighthouse from 'lighthouse';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { seeded, signIn } from './support';

// Lighthouse in CI mode (PWA-051): headless, scripted, and failing on any accessibility or
// best-practices audit that does not pass. It audits the signed-in screens through the
// remote-debugging port of a persistent Chromium profile, so it shares the session cookie.
// Lighthouse leaves the storage alone and applies no throttling of its own: these two
// categories score what is on the page, not how fast it arrives.

const port = 9125 + (Number(process.env.E2E_PORT ?? 8125) - 8125);
let context: BrowserContext;
let profile: string;

test.beforeAll(async () => {
    profile = mkdtempSync(join(tmpdir(), 'bb-lighthouse-'));
    context = await chromium.launchPersistentContext(profile, {
        args: [`--remote-debugging-port=${port}`],
        viewport: { width: 390, height: 844 },
        baseURL: test.info().project.use.baseURL,
    });
    await signIn(context.pages()[0] ?? (await context.newPage()));
});

test.afterAll(async () => {
    await context?.close();
    rmSync(profile, { recursive: true, force: true });
});

type Failure = { audit: string; title: string; items: string[] };

async function failures(path: string): Promise<Failure[]> {
    const url = new URL(path, test.info().project.use.baseURL).toString();
    const result = await lighthouse(url, {
        port,
        output: 'json',
        logLevel: 'error',
        onlyCategories: ['accessibility', 'best-practices'],
        disableStorageReset: true,
        formFactor: 'mobile',
        screenEmulation: { mobile: true, width: 390, height: 844, deviceScaleFactor: 3, disabled: false },
        throttlingMethod: 'provided',
    });
    const lhr = result!.lhr;
    expect(lhr.runtimeError, `Lighthouse could not audit ${path}`).toBeUndefined();
    expect(lhr.finalDisplayedUrl).toBe(url);

    return Object.values(lhr.categories).flatMap((category) =>
        category.auditRefs
            .map((ref) => lhr.audits[ref.id]!)
            .filter((audit) => audit.score !== null && audit.score < 1 && audit.scoreDisplayMode !== 'informative' && audit.scoreDisplayMode !== 'manual')
            .map((audit) => ({
                audit: audit.id,
                title: audit.title,
                items: ((audit.details as { items?: Array<Record<string, unknown>> } | undefined)?.items ?? []).map((item) => {
                    const node = item.node as { selector?: string; snippet?: string } | undefined;
                    return node ? `${node.selector ?? ''} ${node.snippet ?? ''}`.trim() : JSON.stringify(item).slice(0, 200);
                }),
            })),
    );
}

for (const screen of ['today', 'workout', 'meal'] as const) {
    test(`PWA-051 Lighthouse finds no accessibility or best-practices failure on ${screen}`, async () => {
        expect(await failures(seeded()[screen])).toEqual([]);
    });
}
