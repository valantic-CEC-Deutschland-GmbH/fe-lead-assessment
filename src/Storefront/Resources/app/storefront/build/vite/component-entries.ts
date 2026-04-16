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
