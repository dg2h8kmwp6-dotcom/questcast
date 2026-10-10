import { defineConfig, devices } from '@playwright/test';

/**
 * Drives a real session: one host screen plus two phones, each in its own
 * browser context so they get separate sessions and therefore separate
 * characters — the same way two people at a table would.
 */
export default defineConfig({
    testDir: './e2e',
    timeout: 90_000,
    expect: { timeout: 20_000 },
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.APP_URL ?? 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'host', use: { ...devices['Desktop Chrome'] } },
    ],
});
