/**
 * Generic Vite component build config used by build/build-components.js for any bundle
 * that does not supply its own vite.components.config.ts.
 *
 * Environment variables injected by build-components.js:
 *   COMPONENT_ROOT      absolute path to the bundle's views/components/ directory
 *   OUT_DIR             absolute path to the bundle's dist-es/components/ output directory
 *   COMPONENT_NAMESPACE bundle name / Twig namespace (e.g. 'Storefront', 'ComponentTestApp')
 *
 * Output path strategy:
 *   Core ('Storefront'): entry files keep their natural path, e.g. Sw/Filter/Sorting.js.
 *                        Core component names already carry the Sw/ prefix by convention.
 *   Extensions:          entry files are prefixed with the namespace so the dist-es/components/
 *                        tree can be copied flat into the theme without any path rewriting.
 *                        E.g. Wusel/Counter.js  → ComponentTestApp/Wusel/Counter.js
 *                             Wusel/Counter.scss → ComponentTestApp/Wusel/Counter.css
 *                        Vendor chunks:          ComponentTestApp/vendor/debounce-abc123.js
 *
 * Module resolution note:
 *   Component sources live in Resources/views/components/ while npm deps are installed into
 *   Resources/app/storefront/node_modules/.  extensionNodeModulesPlugin() bridges the gap by
 *   resolving bare specifiers via Node's createRequire from the storefront app directory.
 */

import path from 'node:path';
import { createRequire } from 'node:module';
import { defineConfig, type UserConfig } from 'vite';
import { glob } from 'tinyglobby';
import { componentMapPlugin } from './component-map-plugin';
import { scopedSubpathExportsPlugin } from './scoped-subpath-exports-plugin';

const componentRoot = process.env.COMPONENT_ROOT;
const outDir = process.env.OUT_DIR;
const namespace = process.env.COMPONENT_NAMESPACE ?? 'Storefront';

if (!componentRoot || !outDir) {
    throw new Error(
        '[vite.components.generic.config] COMPONENT_ROOT and OUT_DIR env vars must be set.',
    );
}

const isExtension = namespace !== 'Storefront';

// Derive the extension's storefront app dir: OUT_DIR = …/storefront/dist-es/components
const storefrontAppDir = path.resolve(outDir, '../..');
const resolveFromExtension = createRequire(path.join(storefrontAppDir, 'package.json'));

// Core Storefront's app/storefront directory (sibling of this config file's build/ folder).
const coreStorefrontAppDir = path.resolve(import.meta.dirname, '../..');

/**
 * SCSS load paths for component stylesheets.
 *
 * Extensions get their own vendor/ first (if it exists), then fall back to
 * the core Storefront's vendor/ for Bootstrap and common packages.
 * The core Storefront's src/scss/ is always available for skin abstracts.
 *
 * Theme-specific SCSS variables ($sw-*) are intentionally not injected —
 * components must use CSS custom properties (var(--sw-*)) for runtime values.
 */
const scssLoadPaths = [
    path.join(storefrontAppDir, 'vendor'),
    path.join(coreStorefrontAppDir, 'vendor'),
    path.join(coreStorefrontAppDir, 'src/scss'),
];

/**
 * Resolves bare specifiers from the extension's own node_modules directory.
 *
 * Component sources live in Resources/views/components/ while npm deps are
 * installed into Resources/app/storefront/node_modules/.  These are sibling
 * directory branches so the standard upward node_modules crawl never finds the
 * extension's packages.  This plugin bridges the gap by using Node's
 * createRequire to resolve bare specifiers as if require() were called from
 * the storefront app directory.
 */
function extensionNodeModulesPlugin() {
    return {
        name: 'extension-node-modules-resolver',
        enforce: 'pre' as const,
        resolveId(source: string): string | null {
            if (source.startsWith('.') || path.isAbsolute(source)) {
                return null;
            }
            try {
                return resolveFromExtension.resolve(source);
            } catch {
                return null;
            }
        },
    };
}

export default defineConfig(async (): Promise<UserConfig> => {
    const jsFiles = await glob('**/*.{js,ts}', {
        cwd: componentRoot,
        ignore: ['**/*.test.{js,ts}', '**/*.stories.*'],
    });
    const scssFiles = await glob('**/*.scss', {
        cwd: componentRoot,
        ignore: ['**/*.stories.*'],
    });

    // For extensions the entry name carries the namespace prefix so the
    // dist-es/components/ tree can be copied flat without path rewriting.
    // JS:   Wusel/Counter.js   → ComponentTestApp/Wusel/Counter      (key, no ext)
    // SCSS: Wusel/Dusel.scss   → ComponentTestApp/Wusel/Dusel.scss   (key keeps .scss)
    //
    // The .scss extension is kept in SCSS keys so that a component with both a
    // .js and a .scss file of the same base name (e.g. Dusel.js + Dusel.scss)
    // does not produce duplicate keys. The assetFileNames function below strips
    // the embedded .scss from the CSS output filename.
    const makeJsEntryName = (file: string): string => {
        const name = file.replace(/\.(js|ts)$/, '');
        return isExtension ? `${namespace}/${name}` : name;
    };
    const makeScssEntryName = (file: string): string =>
        isExtension ? `${namespace}/${file}` : file;

    const entries: Record<string, string> = {
        ...Object.fromEntries(
            jsFiles.map(file => [makeJsEntryName(file), path.join(componentRoot, file)]),
        ),
        ...Object.fromEntries(
            scssFiles.map(file => [makeScssEntryName(file), path.join(componentRoot, file)]),
        ),
    };

    return {
        css: {
            preprocessorOptions: {
                scss: {
                    loadPaths: scssLoadPaths,
                },
            },
        },
        build: {
            outDir,
            emptyOutDir: true,
            manifest: true,
            sourcemap: process.env.NODE_ENV !== 'production',
            rolldownOptions: {
                input: entries,
                preserveEntrySignatures: 'exports-only',
                external: ['shopware'],
                output: {
                    format: 'es',
                    // Preserve directory structure with a content hash for cache busting.
                    entryFileNames: '[name]-[hash].js',
                    chunkFileNames: isExtension
                        ? `${namespace}/vendor/[name]-[hash].js`
                        : 'vendor/[name]-[hash].js',
                    // SCSS entry keys keep the .scss extension to avoid key collisions.
                    // Rolldown appends .css → 'Ns/Wusel/Dusel.scss.css'; strip .scss before
                    // composing the output path so the file stays 'Ns/Wusel/Dusel-[hash].css'.
                    assetFileNames: (info) => {
                        const firstName = info.names[0] ?? 'asset.css';
                        if (firstName.endsWith('.scss.css')) {
                            return `${firstName.replace(/\.scss\.css$/, '')}-[hash][extname]`;
                        }
                        return '[name]-[hash][extname]';
                    },
                },
            },
        },
        plugins: [
            // scopedSubpathExportsPlugin must come before extensionNodeModulesPlugin so
            // scoped subpath imports are resolved to their ESM entry via `exports` before
            // the CJS fallback resolver can return a .cjs path instead.
            scopedSubpathExportsPlugin(
                // Extension's own node_modules first, then the core Storefront's shared
                // node_modules as fallback for packages the extension doesn't vendor itself.
                path.join(storefrontAppDir, 'node_modules'),
                path.resolve(import.meta.dirname, '../..', 'node_modules'),
            ),
            extensionNodeModulesPlugin(),
            componentMapPlugin(),
        ],
    };
});
