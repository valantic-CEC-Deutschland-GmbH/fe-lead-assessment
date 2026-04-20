<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\System\Locale\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Feature\FeatureException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Locale\Util\BackwardCompatibleNumberFormatter;
use Shopware\Core\Test\Annotation\DisabledFeatures;

/**
 * @internal
 */
#[Package('discovery')]
#[CoversClass(BackwardCompatibleNumberFormatter::class)]
class BackwardCompatibleNumberFormatterTest extends TestCase
{
    public function testItGetsNumberFormatterWithValidLocale(): void
    {
        $numberFormatter = new BackwardCompatibleNumberFormatter('en-GB', \NumberFormatter::DECIMAL);
        static::assertSame($numberFormatter->getLocale(), 'en_GB');
    }

    #[RequiresPhp('>= 8.4.0')]
    #[DisabledFeatures(['v6.8.0.0'])]
    public function testItFallsBackToValidLocaleIfGivenLocaleIsInvalid(): void
    {
        $numberFormatter = new BackwardCompatibleNumberFormatter('us', \NumberFormatter::DECIMAL);
        static::assertSame($numberFormatter->getLocale(), 'en_GB');
    }

    #[RequiresPhp('>= 8.4.0')]
    public function testItThrowsExceptionIfGivenLocaleIsInvalid(): void
    {
        static::expectException(FeatureException::class);
        static::expectExceptionMessage('Tried to access deprecated functionality: The locale "us" is no valid PHP locale. Please use a valid locale.');

        new BackwardCompatibleNumberFormatter('us', \NumberFormatter::DECIMAL);
    }
}
