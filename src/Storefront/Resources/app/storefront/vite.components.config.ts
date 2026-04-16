import path from 'node:path';
import { defineConfig, type UserConfig } from 'vite';
import { buildComponentEntries } from './build/vite/component-entries';
import { componentMapPlugin } from './build/vite/component-map-plugin';
import { devImportMapPlugin } from './build/vite/dev-import-map-plugin';
import { devServerNoticePlugin } from './build/vite/dev-server-notice-plugin';
import { extensionModuleResolverPlugin } from './build/vite/extension-module-resolver-plugin';
import { scopedSubpathExportsPlugin } from './build/vite/scoped-subpath-exports-plugin';
import { themeScssWatcherPlugin } from './build/vite/theme-scss-watcher-plugin';

// Allow the dev server to serve files from the Resources/ tree and the
// project root (needed for /@fs/ URLs to extension component sources).
const resourcesRoot = path.resolve(import.meta.dirname, '../..'); // Resources/
const projectRoot = process.env.PROJECT_ROOT
    ? path.resolve(process.env.PROJECT_ROOT)
    : path.resolve(import.meta.dirname, '../../../../../');

export default defineConfig(async ({ command }): Promise<UserConfig> => {
    const entries = await buildComponentEntries();
    const isServe = command === 'serve';
    return {
        build: {
            outDir: 'dist-es/components',
            emptyOutDir: true,
            manifest: true,
            sourcemap: process.env.NODE_ENV !== 'production',
            rolldownOptions: {
                input: entries,
                // Keep all exports on entry chunks even though nothing inside the build imports
                // them — they are consumed at runtime via dynamic import() by the Shopware
                // component registry.
                preserveEntrySignatures: 'exports-only',
                // 'shopware' is a singleton resolved via import map at runtime — never bundle it.
                external: ['shopware'],
                output: {
                    format: 'es',
                    // Preserve directory structure: Sw/Product/Listing.js
                    entryFileNames: '[name].js',
                    // All vendor chunks go into a flat vendor/ directory with a content hash.
                    chunkFileNames: 'vendor/[name]-[hash].js',
                },
            },
        },
        plugins: [
            componentMapPlugin(),
            devImportMapPlugin(projectRoot),
            devServerNoticePlugin(),
            extensionModuleResolverPlugin(projectRoot),
            scopedSubpathExportsPlugin(path.resolve(import.meta.dirname, 'node_modules')),
            themeScssWatcherPlugin(projectRoot),
        ],
        resolve: {
            alias: {
                // Mirror webpack's resolve.alias so that main.js and plugin entries
                // that use bare 'src/…', 'scss/…', 'assets/…', 'vendor/…' imports
                // resolve correctly when served by the Vite dev server.
                src:    path.resolve(import.meta.dirname, 'src'),
                assets: path.resolve(import.meta.dirname, 'assets'),
                scss:   path.resolve(import.meta.dirname, 'src/scss'),
                vendor: path.resolve(import.meta.dirname, 'vendor'),
                // In dev server mode resolve 'shopware' to the actual source file
                // so Vite can transform /@fs/ component files that import from it.
                // In production builds 'shopware' stays external (resolved via
                // the runtime import map).
                ...(isServe ? { shopware: path.resolve(import.meta.dirname, 'src/shopware.ts') } : {}),
            },
        },
        server: {
            port: Number(process.env.STOREFRONT_VITE_PORT ?? 5175),
            cors: true,
            fs: {
                // Allow Vite to serve component sources from any bundle under
                // the project root via the /@fs/ prefix.
                allow: [resourcesRoot, projectRoot],
            },
        },
    };
});
