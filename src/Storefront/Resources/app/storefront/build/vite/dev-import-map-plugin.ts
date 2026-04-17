import path from 'node:path';
import fs from 'node:fs';
import type { Plugin, ViteDevServer } from 'vite';
import { glob } from 'tinyglobby';
import { compileAsync } from 'sass-embedded';

type BundleEntry = {
    basePath?: string;
    technicalName?: string;
    storefront?: {
        entryFilePath?: string | null;
    };
};

const COMPONENTS_PATH = 'Resources/views/components';

/** URL prefix for the on-the-fly component SCSS middleware. */
const COMP_CSS_PREFIX = '/__sw-comp-css/';

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
/**
 * @param projectRoot  Absolute path to the Shopware project root.
 * @param scssLoadPaths  Additional SCSS load paths forwarded to the on-the-fly
 *                       component SCSS compiler (vendor/, src/scss/, …).
 */
export function devImportMapPlugin(projectRoot: string, scssLoadPaths: string[] = []): Plugin {
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

        configureServer(server: ViteDevServer) {
            // Build namespace → bundle-basePath index so the SCSS middleware can
            // resolve a URL like "/__sw-comp-css/ComponentTestApp/Wusel/Dusel.scss"
            // back to the absolute SCSS file path on disk.
            const pluginsJsonPath = path.join(projectRoot, 'var/plugins.json');
            const bundles: Record<string, BundleEntry> = fs.existsSync(pluginsJsonPath)
                ? (JSON.parse(fs.readFileSync(pluginsJsonPath, 'utf-8')) as Record<string, BundleEntry>)
                : {};

            // namespace ('' for core) → absolute components-root directory
            const nsToCompRoot: Record<string, string> = {};
            for (const [bundleName, bundle] of Object.entries(bundles)) {
                const ns = bundleName === 'Storefront' ? '' : bundleName;
                nsToCompRoot[ns] = path.join(projectRoot, bundle.basePath ?? '', COMPONENTS_PATH);
            }

            // Middleware: serve individual component SCSS files as compiled CSS so
            // PHP can use them as <link rel="stylesheet"> targets in dev mode —
            // matching the per-file CSS behaviour of the production Vite build.
            server.middlewares.use(COMP_CSS_PREFIX, (req, res, next) => {
                const relUrl = req.url?.replace(/^\//, '') ?? '';

                // Determine namespace and file path from the URL.
                // URLs look like "ComponentTestApp/Wusel/Dusel.scss" (namespaced)
                // or "Sw/Header/Navbar.scss" (core, no namespace).
                let scssAbsPath: string | undefined;
                for (const ns of Object.keys(nsToCompRoot)) {
                    const compRoot = nsToCompRoot[ns];
                    if (compRoot === undefined) continue;

                    const prefix = ns ? `${ns}/` : '';
                    if (!relUrl.startsWith(prefix)) continue;

                    const candidate = path.join(compRoot, relUrl.slice(prefix.length));
                    if (fs.existsSync(candidate)) {
                        scssAbsPath = candidate;
                        break;
                    }
                }

                if (!scssAbsPath) {
                    next();
                    return;
                }

                compileAsync(scssAbsPath, { loadPaths: scssLoadPaths, quietDeps: true })
                    .then(result => {
                        res.setHeader('Content-Type', 'text/css');
                        res.end(result.css);
                    })
                    .catch(() => next());
            });

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

                // CSS URLs for dev mode:
                // 1. Main theme SCSS (sw-theme-scss middleware) — when theme-files.json exists.
                // 2. Individual component SCSS files — served on-the-fly by the
                //    /__sw-comp-css/ middleware above, mirroring the per-file CSS
                //    behaviour of the production Vite build.
                const themeFilesPath = path.join(projectRoot, 'var/theme-files.json');
                const styles: string[] = fs.existsSync(themeFilesPath)
                    ? [`${origin}/theme-scss/all.css`]
                    : [];

                for (const [bundleName, bundle] of Object.entries(bundles)) {
                    const namespace = bundleName === 'Storefront' ? '' : bundleName;
                    const compRoot = path.join(projectRoot, bundle.basePath ?? '', COMPONENTS_PATH);
                    if (!fs.existsSync(compRoot)) continue;

                    const scssFiles = await glob('**/*.scss', {
                        cwd: compRoot,
                        ignore: ['**/*.stories.*'],
                    });

                    for (const file of scssFiles) {
                        const namespacedPath = namespace ? `${namespace}/${file}` : file;
                        styles.push(`${origin}${COMP_CSS_PREFIX}${namespacedPath}`);
                    }
                }

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
