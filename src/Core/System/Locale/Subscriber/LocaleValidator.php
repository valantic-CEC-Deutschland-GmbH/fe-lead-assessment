<?php declare(strict_types=1);

namespace Shopware\Core\System\Locale\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Locale\Exception\InvalidLocaleCodeException;
use Shopware\Core\System\Locale\LocaleDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
#[Package('discovery')]
class LocaleValidator implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'preWriteValidateEvent',
        ];
    }

    public function preWriteValidateEvent(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if ($command instanceof DeleteCommand || $command->getEntityName() !== LocaleDefinition::ENTITY_NAME) {
                continue;
            }

            $code = $command->getPayload()['code'] ?? null;

            if (!\is_string($code)) {
                continue;
            }

            try {
                /** @phpstan-ignore new.resultUnused */
                new \NumberFormatter($code, \NumberFormatter::DECIMAL);
            } catch (\ValueError) {
                $event->getExceptions()->add(new InvalidLocaleCodeException($code));
            }
        }
    }
}
