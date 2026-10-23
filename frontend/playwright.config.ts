import { defineConfig, devices } from '@playwright/test';

// E2E（docs/inertia-removal-migration.md §5.6）。Compose の frontend-e2e service（test profile）で実行する:
//   docker compose run --rm frontend-e2e npm run test:e2e
// frontend-e2e は nginx-e2e の network namespace を共有するため、Nginx の入口は http://localhost になる
// （Vite の allowedHosts と、laravel.e2e の SANCTUM_STATEFUL_DOMAINS に一致させる）。
// Vite/Laravel の container へは直接つながず、必ず Nginx を経由する。
const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost';

export default defineConfig({
    testDir: 'e2e',
    testMatch: '**/*.spec.ts',
    outputDir: 'test-results',
    // Vite の dev server と E2E 用 DB を全 test で共有するため、初期は直列で動かす
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    use: {
        baseURL,
        locale: 'ja-JP',
        timezoneId: 'Asia/Tokyo',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    // 初期は Chromium のみ（Firefox/WebKit は必要になった時点で追加する）
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
