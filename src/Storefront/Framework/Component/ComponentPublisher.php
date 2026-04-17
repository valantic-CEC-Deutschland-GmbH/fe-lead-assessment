<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Component;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatch;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInput;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Filesystem\Filesystem as LocalFilesystem;
use Symfony\Component\Finder\Finder;

/**
 * Publishes built component JS and CSS files from each bundle's
 * `Resources/app/storefront/dist-es/components/` into the shared
 * `public/components/` directory so they can be served at fixed,
 * theme-agnostic URLs.
 *
 * After publishing it writes `var/cache/component-manifest.json` — a map
 * of component tag → { js, css } URL paths that ThemeCompiler reads to
 * build the runtime import map and CSS list without re-copying files on
 * every `theme:compile`.
 *
 * Public component directory layout (flat, namespace collision-free due to
 * the Vite build's namespace-prefix strategy):
 *
 *   public/components/
 *     Sw/Filter/Sorting.js                    ← core Storefront
 *     CustomApp/Button/Primary.js             ← extension JS
 *     CustomApp/Button/Primary.css            ← extension CSS
 *     CustomApp/vendor/debounce-abc123.js     ← vendor chunk
 *
 * Component manifest format (`var/cache/component-manifest.json`):
 *
 *   {
 *     "CustomApp:Button:Primary": {
 *       "js":  "/components/CustomApp/Button/Primary.js",
 *       "css": "/components/CustomApp/Button/Primary.css"
 *     }
 *   }
 *
 * The `css` key is omitted when no CSS was emitted for that component.
 */
#[Package('framework')]
class ComponentPublisher
{
    /**
     * Path inside the public filesystem where component assets are stored.
     * Maps to `public/components/` on disk.
     */
    public const PUBLIC_COMPONENTS_DIR = 'components';

    /**
     * Path inside the temp filesystem where the combined manifest is written.
     * Maps to `var/cache/component-manifest.json` on disk.
     */
    public const MANIFEST_PATH = 'cache/component-manifest.json';

    private const DIST_ES_COMPONENTS = 'Resources/app/storefront/dist-es/components';
    private const VITE_MANIFEST = '.vite/manifest.json';

    /**
     * @internal
     */
    public function __construct(
        private readonly FilesystemOperator $publicFilesystem,
        private readonly FilesystemOperator $tempFilesystem,
        private readonly string $projectDir,
        private readonly string $visibility = Visibility::PUBLIC,
        private readonly LocalFilesystem $localFilesystem = new LocalFilesystem(),
    ) {
    }

    /**
     * Publishes component assets for every bundle listed in `var/plugins.json`
     * that has a `dist-es/components/` Vite build output.
     *
     * Called during deployment (via `storefront:publish-components --all`) to
     * seed the public/components/ directory from scratch.
     */
    public function publishAll(): void
    {
        $pluginsJson = $this->projectDir . '/var/plugins.json';

        if (!$this->localFilesystem->exists($pluginsJson)) {
            return;
        }

        $plugins = json_decode((string) file_get_contents($pluginsJson), true, 512, \JSON_THROW_ON_ERROR);

        $manifest = [];

        foreach ($plugins as $bundleName => $config) {
            $bundleAbsPath = $this->projectDir . '/' . ltrim((string) ($config['basePath'] ?? ''), '/');
            $bundleManifest = $this->publishBundleInternal($bundleAbsPath, (string) $bundleName);
            $manifest = array_merge($manifest, $bundleManifest);
        }

        $this->writeComponentManifest($manifest);
    }

    /**
     * Publishes component assets for a single bundle and merges the result
     * into the existing component manifest.
     *
     * Called by the plugin/app lifecycle subscribers when a bundle is
     * activated or updated.
     */
    public function publishBundle(string $bundleAbsPath, string $bundleName): void
    {
        $newEntries = $this->publishBundleInternal($bundleAbsPath, $bundleName);

        if ($newEntries === []) {
            return;
        }

        $existing = $this->readComponentManifest();
        $this->writeComponentManifest(array_merge($existing, $newEntries));
    }

    /**
     * Removes all published assets for the given bundle and regenerates the
     * component manifest without those entries.
     *
     * Called by the plugin/app lifecycle subscribers when a bundle is
     * deactivated or uninstalled.
     */
    public function unpublish(string $bundleName): void
    {
        // Remove the bundle's subdirectory from public/components/.
        // Core Storefront uses bare component paths (e.g. Sw/…); extensions
        // use a namespace-prefixed path (e.g. ComponentTestApp/…) matching the
        // bundle name.
        $publicDir = self::PUBLIC_COMPONENTS_DIR . '/' . $bundleName;
        try {
            if ($this->publicFilesystem->directoryExists($publicDir)) {
                $this->publicFilesystem->deleteDirectory($publicDir);
            }
        } catch (FilesystemException) {
            // Best-effort: directory may already be absent.
        }

        // Regenerate the manifest without this bundle's entries.
        $existing = $this->readComponentManifest();
        $filtered = array_filter(
            $existing,
            static fn (string $tag): bool => !str_starts_with($tag, $bundleName . ':'),
            \ARRAY_FILTER_USE_KEY,
        );

        $this->writeComponentManifest($filtered);
    }

    /**
     * Builds scoped import map entries for extension vendor chunks.
     *
     * Each extension bundle that has published a vendor-map.json gets a scope
     * entry so that vendor-specifier imports are resolved to the correct
     * content-hashed chunk when loading modules inside that extension's scope.
     *
     * Core Storefront vendor chunks are top-level imports handled separately
     * by ThemeCompiler (it already knows the core storefrontJsDir).
     *
     * @return array<string, array<string, string>>  scopeKey → [specifier → chunkUrl]
     */
    public function buildExtensionVendorScopes(string $publicBaseUrl): array
    {
        $pluginsJson = $this->projectDir . '/var/plugins.json';

        if (!$this->localFilesystem->exists($pluginsJson)) {
            return [];
        }

        /** @var array<string, array{basePath?: string}> $plugins */
        $plugins = json_decode((string) file_get_contents($pluginsJson), true, 512, \JSON_THROW_ON_ERROR);

        $publicBaseUrl = rtrim($publicBaseUrl, '/');
        $scopes = [];

        foreach ($plugins as $bundleName => $config) {
            if ($bundleName === 'Storefront') {
                continue;
            }

            $bundleStorefrontDir = $this->projectDir . '/' . ltrim((string) ($config['basePath'] ?? ''), '/')
                . self::DIST_ES_COMPONENTS;
            $vendorMapPath = $bundleStorefrontDir . '/' . self::VITE_MANIFEST;
            $vendorMapPath = str_replace('manifest.json', 'vendor-map.json', $vendorMapPath);

            if (!$this->localFilesystem->exists($vendorMapPath)) {
                continue;
            }

            try {
                /** @var array<string, string>|null $vendorMap */
                $vendorMap = json_decode((string) file_get_contents($vendorMapPath), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($vendorMap) || $vendorMap === []) {
                continue;
            }

            $scopeKey = $publicBaseUrl . '/components/' . $bundleName . '/';
            foreach ($vendorMap as $specifier => $chunkPath) {
                $scopes[$scopeKey][$specifier] = $publicBaseUrl . '/components/' . $chunkPath;
            }
        }

        return $scopes;
    }

    /**
     * Returns the current component manifest or an empty array when none exists.
     *
     * @return array<string, array{js?: string, css?: string}>
     */
    public function readComponentManifest(): array
    {
        try {
            if (!$this->tempFilesystem->fileExists(self::MANIFEST_PATH)) {
                return [];
            }

            /** @var array<string, array{js?: string, css?: string}> $data */
            $data = json_decode($this->tempFilesystem->read(self::MANIFEST_PATH), true, 512, \JSON_THROW_ON_ERROR);

            return \is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Copies all files from a bundle's dist-es/components/ tree to
     * public/components/ and returns the manifest entries produced.
     *
     * @return array<string, array{js?: string, css?: string}>
     */
    private function publishBundleInternal(string $bundleAbsPath, string $bundleName): array
    {
        $distDir = $bundleAbsPath . '/' . self::DIST_ES_COMPONENTS;
        $viteManifestPath = $distDir . '/' . self::VITE_MANIFEST;

        if (!$this->localFilesystem->exists($viteManifestPath)) {
            return [];
        }

        // Copy all non-.vite/ files into public/components/.
        $copyBatch = [];
        foreach ((new Finder())->files()->in($distDir) as $file) {
            $relative = $file->getRelativePathname();
            // Skip Vite internals (.vite/manifest.json, .vite/vendor-map.json).
            // vendor-map.json is read from the local dist-es/ directory by
            // ThemeCompiler; it must not be published to public/ because all
            // bundles would write to the same path and overwrite each other.
            if (str_starts_with($relative, '.vite' . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            $targetPath = self::PUBLIC_COMPONENTS_DIR . '/' . str_replace(\DIRECTORY_SEPARATOR, '/', $relative);
            $copyBatch[] = new CopyBatchInput($file->getPathname(), [$targetPath], $this->visibility);
        }

        if ($copyBatch !== []) {
            CopyBatch::copy($this->publicFilesystem, ...$copyBatch);
        }

        // Build manifest entries from the Vite manifest.
        return $this->buildManifestEntries($viteManifestPath, $bundleName);
    }

    /**
     * Parses a bundle's .vite/manifest.json and derives component tag → URL entries.
     *
     * Vite manifest entry format (keys are source paths relative to Vite root):
     *   {
     *     "../../views/components/Button/Primary.js": {
     *       "file": "CustomApp/Button/Primary.js",
     *       "name": "CustomApp/Button/Primary",
     *       "src": "../../views/components/Button/Primary.js",
     *       "isEntry": true
     *     }
     *   }
     *
     * SCSS entries produce a CSS `file`; JS entries may list CSS files in a `css` array.
     *
     * @return array<string, array{js?: string, css?: string}>
     */
    private function buildManifestEntries(string $viteManifestPath, string $bundleName): array
    {
        try {
            /** @var array<string, array{file: string, name?: string, src?: string, isEntry?: bool, css?: list<string>}> $viteManifest */
            $viteManifest = json_decode((string) file_get_contents($viteManifestPath), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        // First pass: collect CSS files associated with each named JS entry via
        // the `css` array (Vite sometimes puts SCSS imports here rather than as
        // a standalone entry).
        /** @var array<string, list<string>> $jsToCssFiles  entry name → css file paths */
        $jsToCssFiles = [];
        foreach ($viteManifest as $entry) {
            if (($entry['isEntry'] ?? false) !== true || !isset($entry['name']) || $entry['name'] === '') {
                continue;
            }
            if (isset($entry['css']) && $entry['css'] !== []) {
                $jsToCssFiles[$entry['name']] = $entry['css'];
            }
        }

        $result = [];

        foreach ($viteManifest as $entry) {
            if (($entry['isEntry'] ?? false) !== true || !isset($entry['name']) || $entry['name'] === '') {
                continue;
            }

            $entryName = $entry['name']; // e.g. "CustomApp/Button/Primary" or "CustomApp/Button/Primary.scss"
            $outputFile = $entry['file']; // e.g. "CustomApp/Button/Primary.js" or "CustomApp/Button/Primary-hash.css"

            // SCSS entry keys keep the .scss extension to prevent key collisions with same-named
            // JS entries at build time. Strip it here so the tag stays clean.
            $entryName = preg_replace('/\.scss$/', '', $entryName) ?? $entryName;

            // Derive the component tag: "CustomApp/Button/Primary" → "CustomApp:Button:Primary"
            $tag = str_replace('/', ':', $entryName);

            $publicBase = '/' . self::PUBLIC_COMPONENTS_DIR . '/';

            if (str_ends_with($outputFile, '.css')) {
                // Pure CSS/SCSS entry — contributes only a CSS URL.
                $result[$tag]['css'] = $publicBase . $outputFile;
            } elseif (str_ends_with($outputFile, '.js')) {
                // JS entry — contributes a JS URL and optionally inline CSS files.
                $result[$tag]['js'] = $publicBase . $outputFile;

                // Attach CSS that Vite listed in the `css` array of this entry.
                if (isset($jsToCssFiles[$entryName]) && $jsToCssFiles[$entryName] !== []) {
                    // Use only the first CSS file per component for now.
                    $result[$tag]['css'] = $publicBase . $jsToCssFiles[$entryName][0];
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, array{js?: string, css?: string}> $manifest
     */
    private function writeComponentManifest(array $manifest): void
    {
        try {
            $this->tempFilesystem->write(
                self::MANIFEST_PATH,
                json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            );
        } catch (\Throwable) {
            // Non-critical: ThemeCompiler falls back to building an empty import map.
        }
    }
}
