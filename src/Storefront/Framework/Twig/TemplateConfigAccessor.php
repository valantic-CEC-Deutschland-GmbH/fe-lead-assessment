<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Twig;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Theme\ThemeConfigValueAccessor;
use Shopware\Storefront\Theme\ThemeScripts;

#[Package('framework')]
class TemplateConfigAccessor
{
    /**
     * @internal
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly ThemeConfigValueAccessor $themeConfigAccessor,
        private readonly ThemeScripts $themeScripts,
        private readonly string $kernelEnvironment = 'prod',
    ) {
    }

    /**
     * @return string|bool|array<mixed>|float|int|null
     */
    public function config(string $key, ?string $salesChannelId)
    {
        $static = $this->getStatic();

        if (\array_key_exists($key, $static)) {
            return $static[$key];
        }

        return $this->systemConfigService->get($key, $salesChannelId);
    }

    /**
     * @return string|bool|array<string, mixed>|float|int|null
     */
    public function theme(string $key, SalesChannelContext $context, ?string $themeId)
    {
        return $this->themeConfigAccessor->get($key, $context, $themeId);
    }

    /**
     * @return array<int, string> $items
     */
    public function scripts(): array
    {
        $scripts = [];

        foreach ($this->themeScripts->getThemeScripts() as $script) {
            $scripts[] = $script;
        }

        return $scripts;
    }

    /**
     * Returns the full import map data: top-level imports, optional scoped imports for extensions,
     * and optional ordered lists of Vite dev-server CSS and JS URLs.
     *
     * When the Vite component dev server is running it writes a flag file that
     * IS the complete map (all entries already contain full dev-server URLs).
     * That map is returned verbatim, including the `styles` and `scripts` keys
     * written by the dev plugins so that the template can inject <link> and
     * <script> tags without separate function calls.
     *
     * In production the stored map already contains full URLs pre-computed at theme
     * compile time by ThemeCompiler::buildComponentImportMap(). `scopes`, `styles`,
     * and `scripts` are omitted when not applicable.
     *
     * @return array{imports: array<string, string>, scopes?: array<string, array<string, string>>, styles?: list<string>, scripts?: list<string>}
     */
    public function componentImportMap(): array
    {
        // Vite dev server running: the flag file already provides the complete map.
        // Only active in the dev environment — never in production or test.
        if ($this->kernelEnvironment === 'dev') {
            $devMap = $this->themeScripts->getDevImportMap();
            if ($devMap !== null) {
                return $devMap;
            }
        }

        return $this->themeScripts->getComponentImportMap() ?? ['imports' => []];
    }

    /**
     * @return array<string, int|string|bool> $items
     */
    private function getStatic(): array
    {
        return [
            'seo.descriptionMaxLength' => 255,
            'cms.revocationNoticeCmsPageId' => '00B9A8636F954277AE424E6C1C36A1F5',
            'cms.taxCmsPageId' => '00B9A8636F954277AE424E6C1C36A1F5',
            'cms.tosCmsPageId' => '00B9A8636F954277AE424E6C1C36A1F5',
            'confirm.revocationNotice' => true,
        ];
    }
}
