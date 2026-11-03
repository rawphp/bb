import { defineConfig, devices } from '@playwright/test';

// tests/e2e: Chromium against the built app (vite build runs first in scripts/check.sh), served by
// scripts/e2e-server.sh on a throwaway, seeded MySQL. Every spec runs on the phone project at
// 390 × 844. Tests tagged @desktop run on the desktop project at 1440 × 900 instead (PLT-002, NAV-003).
const port = Number(process.env.E2E_PORT ?? 8125);

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    workers: 1,
    forbidOnly: true,
    retries: 0,
    timeout: 120_000,
    reporter: 'list',
    use: {
        browserName: 'chromium',
        baseURL: `http://127.0.0.1:${port}`,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'phone',
            grepInvert: /@desktop/,
            use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } },
        },
        {
            name: 'desktop',
            grep: /@desktop/,
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
    ],
    webServer: {
        command: `bash scripts/e2e-server.sh ${port}`,
        url: `http://127.0.0.1:${port}/up`,
        timeout: 120_000,
        reuseExistingServer: false,
        // SIGTERM lets scripts/e2e-server.sh stop its MySQL and remove its data directory.
        gracefulShutdown: { signal: 'SIGTERM', timeout: 10_000 },
    },
});
