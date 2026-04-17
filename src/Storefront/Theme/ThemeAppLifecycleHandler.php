<?php declare(strict_types=1);

namespace Shopware\Storefront\Theme;

use Shopware\Core\Framework\App\Event\AppActivatedEvent;
use Shopware\Core\Framework\App\Event\AppChangedEvent;
use Shopware\Core\Framework\App\Event\AppDeactivatedEvent;
use Shopware\Core\Framework\App\Event\AppUpdatedEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Storefront\Framework\Component\ComponentPublisher;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\AbstractStorefrontPluginConfigurationFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
#[Package('framework')]
class ThemeAppLifecycleHandler implements EventSubscriberInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly StorefrontPluginRegistry $themeRegistry,
        private readonly AbstractStorefrontPluginConfigurationFactory $themeConfigFactory,
        private readonly ThemeLifecycleHandler $themeLifecycleHandler,
        private readonly ComponentPublisher $componentPublisher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AppUpdatedEvent::class => 'handleAppActivationOrUpdate',
            AppActivatedEvent::class => 'handleAppActivationOrUpdate',
            AppDeactivatedEvent::class => 'handleUninstall',
        ];
    }

    public function handleAppActivationOrUpdate(AppChangedEvent $event): void
    {
        $app = $event->getApp();
        if (!$app->isActive()) {
            return;
        }

        $configurationCollection = $this->themeRegistry->getConfigurations();
        $config = $configurationCollection->getByTechnicalName($app->getName());

        if (!$config) {
            $config = $this->themeConfigFactory->createFromApp($app->getName(), $app->getPath());
            $configurationCollection = clone $configurationCollection;
            $configurationCollection->add($config);
        }

        $this->themeLifecycleHandler->handleThemeInstallOrUpdate(
            $config,
            $configurationCollection,
            $event->getContext()
        );

        $appPath = $app->getPath();
        if ($appPath !== '') {
            $this->componentPublisher->publishBundle($appPath, $app->getName());
        }
    }

    public function handleUninstall(AppDeactivatedEvent $event): void
    {
        $appName = $event->getApp()->getName();
        $config = $this->themeRegistry->getConfigurations()->getByTechnicalName($appName);

        $this->componentPublisher->unpublish($appName);

        if (!$config) {
            return;
        }

        $this->themeLifecycleHandler->handleThemeUninstall($config, $event->getContext());
    }
}
