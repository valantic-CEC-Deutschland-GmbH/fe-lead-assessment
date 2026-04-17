import path from 'node:path';
import { glob } from 'tinyglobby';

/**
 * Absolute path to the Storefront core component root.
 * Components live two levels above the app/storefront package:
 *   build/ → storefront/ → app/ → Resources/ → views/components
 */
export const componentRoot = path.resolve(import.meta.dirname, '../../../views/components');

/**
 * Globs all non-test JS/TS component files under componentRoot and returns
 * them as a Rolldown input map keyed by the path-without-extension.
 *
 *   'Sw/Header/Navbar.ts' → { 'Sw/Header/Navbar': '/abs/path/…/Navbar.ts' }
 */
export async function buildComponentEntries(): Promise<Record<string, string>> {
    const files = await glob('**/*.{js,ts}', {
        cwd: componentRoot,
        ignore: ['**/*.test.{js,ts}', '**/*.stories.*'],
    });

    return Object.fromEntries(
        files.map(file => [
            file.replace(/\.(js|ts)$/, ''),
            path.join(componentRoot, file),
        ]),
    );
}

/**
 * Globs all SCSS component files under componentRoot and returns them as a
 * Rolldown input map.
 *
 * The `.scss` extension is intentionally kept in the entry key:
 *
 *   'Sw/Header/Navbar.scss' → { 'Sw/Header/Navbar.scss': '/abs/path/…/Navbar.scss' }
 *
 * This prevents a silent key collision when a JS/TS file and an SCSS file share
 * the same base name (e.g. `Dusel.js` + `Dusel.scss`). Without the extension the
 * two entries would map to the same key and JavaScript object spread would silently
 * drop the JS entry. The companion `assetFileNames` function in each Vite config
 * strips the `.scss` suffix so the CSS output filename remains clean:
 *
 *   Rolldown asset name: 'Sw/Header/Navbar.scss.css' → output: 'Sw/Header/Navbar-[hash].css'
 */
export async function buildComponentScssEntries(): Promise<Record<string, string>> {
    const files = await glob('**/*.scss', {
        cwd: componentRoot,
        ignore: ['**/*.stories.*'],
    });

    return Object.fromEntries(
        files.map(file => [
            file, // Keep .scss extension to avoid collision with same-named JS entries
            path.join(componentRoot, file),
        ]),
    );
}
