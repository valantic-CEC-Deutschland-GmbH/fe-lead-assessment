import path from 'node:path';
import fs from 'node:fs';
import type { Plugin, ViteDevServer } from 'vite';
import { glob } from 'tinyglobby';

type BundleEntry = {
    basePath?: string;
    technicalName?: string;
    storefront?: {
        entryFilePath?: string | null;
    };
};

const COMPONENTS_PATH = 'Resources/views/components';

/**
 * Converts a component file path (relative to its component root) to the
 * colon-separated tag used in `data-component` attributes and the import map.
 *
 *   'Sw/Header/Navbar.ts'      → 'Sw:Header:Navbar'
 *   'Wusel/Counter.ts' (+ ns)  → 'ComponentTestApp:Wusel:Counter'
 */
function fileToTag(relPath: string, namespace: string | undefined): string {
    const withoutExt = relPath.replace(/\.(ts|js)$/, '');
    const colonPath = withoutExt.split('/').join(':');
    return namespace ? `${namespace}:${colonPath}` : colonPath;
}

/**
 * Vite plugin that manages the component dev flag file
 * (`var/cache/storefront_components.dev.json`).
 *
 * When the Vite dev server starts it writes a JSON object with:
 *
 *   imports  — a complete ES module import map that PHP injects as
 *              `<script type="importmap">`. Every bare specifier
 *              (`shopware`, `Sw:Header:Navbar`, …) points directly to the
 *              running dev server, so no URL rewriting is needed in PHP.
 *
 *   styles   — an ordered array of Vite dev-server CSS URLs produced by the
 *              sw-theme-scss plugin. PHP uses these for `<link>` tags
 *              instead of the precompiled theme CSS. Present only when
 *              var/theme-files.json exists.
 *
 *   scripts  — an ordered array of Vite dev-server JS entry URLs. PHP uses
 *              these to replace the compiled theme JS bundle with live Vite
 *              modules. The first entry is always the core storefront
 *              main.js; any plugin bundles with a storefront entryFilePath
 *              follow in plugins.json order. Present only when
 *              var/plugins.json exists.
 *
 * A single flag file carries both concerns so PHP only needs to check for
 * one file to know whether the Vite dev server is running.
 *
 * When the dev server stops the file is removed and the Storefront
 * transparently falls back to the production import map and compiled CSS.
 */
export function devImportMapPlugin(projectRoot: string): Plugin {
    const flagFile = path.join(projectRoot, 'var/cache/storefront_components.dev.json');
    let viteRoot = '';

    const cleanup = (): void => {
        try {
            fs.unlinkSync(flagFile);
        } catch {
            // File already gone or never created — harmless.
        }
    };

    return {
        name: 'sw-dev-import-map',
        // Only active during `vite` (dev server), not `vite build`.
        apply: 'serve',

        configResolved(config) {
            viteRoot = config.root;
        },

        async configureServer(server: ViteDevServer) {
            const write = async (): Promise<void> => {
                const port = server.config.server.port ?? 5175;
                const origin = `http://localhost:${port}`;
                const imports: Record<string, string> = {};

                // shopware runtime module — lives inside the Vite root so it
                // gets a clean URL without the /@fs/ prefix.
                const shopwareSrc = path.join(viteRoot, 'src/shopware.ts');
                if (fs.existsSync(shopwareSrc)) {
                    imports['shopware'] = `${origin}/src/shopware.ts`;
                }

                // All component files from every registered bundle.
                const pluginsJsonPath = path.join(projectRoot, 'var/plugins.json');
                const bundles = fs.existsSync(pluginsJsonPath)
                    ? (JSON.parse(fs.readFileSync(pluginsJsonPath, 'utf-8')) as Record<string, BundleEntry>)
                    : {};

                for (const [bundleName, bundle] of Object.entries(bundles)) {
                    // The core Storefront bundle uses bare component names
                    // (e.g. 'Sw:Header:Navbar'); all other bundles are prefixed
                    // with their bundle name as a namespace.
                    const namespace = bundleName === 'Storefront' ? undefined : bundleName;
                    const compRoot = path.join(projectRoot, bundle.basePath ?? '', COMPONENTS_PATH);

                    if (!fs.existsSync(compRoot)) continue;

                    const files = await glob('**/*.{js,ts}', {
                        cwd: compRoot,
                        ignore: ['**/*.test.{js,ts}', '**/*.stories.*'],
                    });

                    for (const file of files) {
                        const tag = fileToTag(file, namespace);
                        const absPath = path.join(compRoot, file);
                        // Component sources live outside the Vite root, so they
                        // are served via the /@fs/ prefix (Vite allows this when
                        // server.fs.allow covers the project root).
                        imports[tag] = `${origin}/@fs${absPath}`;
                    }
                }

                // Single CSS URL from the sw-theme-scss plugin middleware.
                // All SCSS entries are compiled together as one virtual document,
                // so a single URL is sufficient. Included here so PHP only needs
                // to check one flag file.
                const themeFilesPath = path.join(projectRoot, 'var/theme-files.json');
                const styles = fs.existsSync(themeFilesPath)
                    ? [`${origin}/theme-scss/all.css`]
                    : [];

                // JS bundle entry URLs — replaces the Webpack hot proxy in dev.
                // Core storefront main.js lives inside the Vite root so it gets a
                // clean URL; plugin entries are outside and use the /@fs/ prefix.
                // Mirrors the webpack.config.js pluginEntries filter: only bundles
                // with a storefront.entryFilePath are included, and technicalName
                // 'storefront' is the core entry, not a plugin.
                const scripts: string[] = [];
                if (fs.existsSync(pluginsJsonPath)) {
                    // Core storefront entry is always first.
                    scripts.push(`${origin}/src/main.js`);

                    for (const [, bundle] of Object.entries(bundles)) {
                        const entryFilePath = bundle.storefront?.entryFilePath;
                        if (!entryFilePath || bundle.technicalName === 'storefront') continue;
                        const absEntry = path.join(projectRoot, bundle.basePath ?? '', entryFilePath);
                        scripts.push(`${origin}/@fs${absEntry}`);
                    }
                }

                const devMap = { imports, styles, scripts };
                fs.mkdirSync(path.dirname(flagFile), { recursive: true });
                fs.writeFileSync(flagFile, JSON.stringify(devMap, null, 2));
                server.config.logger.info(
                    `[sw-dev-import-map] dev flag file written → ${flagFile}`,
                    { timestamp: true },
                );
            };

            // Write the map once the HTTP server is ready.
            server.httpServer?.once('listening', () => void write());

            // Clean up on graceful shutdown and common signals.
            server.httpServer?.once('close', () => { cleanup(); });
            process.once('SIGINT', () => { cleanup(); });
            process.once('SIGTERM', () => { cleanup(); });
        },
    };
}
