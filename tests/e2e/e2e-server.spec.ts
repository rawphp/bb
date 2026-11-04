import { expect, test } from '@playwright/test';
import { existsSync, rmSync, writeFileSync } from 'node:fs';

// A Vite dev server (Herd starts one for a linked site) leaves public/hot in the checkout. The e2e server must
// still serve the built front end, and must leave the hot file alone so that dev server keeps working.
const hot = 'public/hot';

test('the e2e server serves the built front end even when public/hot exists', async ({ request }) => {
    // Only a hot file this test wrote is removed; one a running dev server wrote stays.
    const wrote = !existsSync(hot);
    if (wrote) writeFileSync(hot, 'http://127.0.0.1:5173');
    try {
        const html = await (await request.get('/')).text();
        expect(html).toContain('/build/');
        expect(html).not.toContain('/@vite/client');
        expect(existsSync(hot)).toBe(true);
    } finally {
        if (wrote) rmSync(hot, { force: true });
    }
});
