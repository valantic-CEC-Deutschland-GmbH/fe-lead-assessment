<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Twig\Components;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class TwigComponent
{
    private const MAIN_NAMESPACE = 'Storefront';

    public function __construct(
        public string $name,
        public string $path,
        public string $namespace,
    ) {
    }

    public function getBaseName(): string
    {
        $nameParts = explode(':', $this->name);

        if (\count($nameParts) <= 1) {
            return $this->name;
        }

        return $nameParts[\count($nameParts) - 1];
    }

    public function getTag(): string
    {
        $name = $this->name;

        if ($this->isIndexComponent()) {
            $name = str_replace(':index', '', $name);
        }

        if ($this->namespace !== self::MAIN_NAMESPACE) {
            return $this->namespace . ':' . $name;
        }

        return $name;
    }

    public function isIndexComponent(): bool
    {
        return strcasecmp(basename($this->path), 'index.html.twig') === 0;
    }

}
