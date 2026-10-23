import { defineConfig, mergeConfig } from 'vitest/config';
import viteConfig from './vite.config.ts';

// unit/component/router test（docs/inertia-removal-migration.md §5.6）。
// alias と Vue plugin は vite.config.ts を共有する。E2E（e2e/*.spec.ts）は Playwright が担当し、ここでは収集しない。
export default mergeConfig(
    viteConfig,
    defineConfig({
        test: {
            include: ['tests/**/*.test.ts'],
            environment: 'jsdom',
            setupFiles: ['tests/setup.ts'],
            // vi.stubGlobal('fetch', ...) 等を test ごとに戻す
            unstubGlobals: true,
            restoreMocks: true,
        },
    }),
);
