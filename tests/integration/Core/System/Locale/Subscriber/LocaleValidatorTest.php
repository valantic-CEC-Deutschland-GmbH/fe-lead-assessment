<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\System\Locale\Subscriber;

use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelFunctionalTestBehaviour;
use Shopware\Core\System\Locale\Exception\InvalidLocaleCodeException;
use Shopware\Core\System\Locale\LocaleCollection;
use Shopware\Core\System\Locale\LocaleEntity;

/**
 * @internal
 */
#[Package('discovery')]
class LocaleValidatorTest extends TestCase
{
    use SalesChannelFunctionalTestBehaviour;

    /**
     * @var EntityRepository<LocaleCollection>
     */
    private EntityRepository $localeRepository;

    protected function setUp(): void
    {
        $this->localeRepository = $this->getContainer()->get('locale.repository');
    }

    #[RequiresPhp('>= 8.4.0')]
    public function testItCannotCreateLocaleWithInvalidCode(): void
    {
        try {
            $this->localeRepository->create([
                [
                    'code' => 'us',
                    'name' => 'English',
                    'territory' => 'USA',
                ],
            ], Context::createDefaultContext());
        } catch (WriteException $e) {
            static::assertInstanceOf(InvalidLocaleCodeException::class, $e->getExceptions()[0]);
            static::assertSame(
                'Cannot create or update locale with invalid code "us"',
                $e->getExceptions()[0]->getMessage()
            );

            return;
        }

        static::fail('WriteException not thrown');
    }

    public function testItValidatesAllDefaultLocalesWithoutErrors(): void
    {
        $locales = $this->localeRepository->search(new Criteria(), Context::createDefaultContext())->getElements();

        $payload = array_values(array_map(static fn (LocaleEntity $locale) => [
            'id' => $locale->getId(),
            'code' => $locale->getCode(),
            'name' => 'foobar',
        ], $locales));

        static::assertCount(
            0,
            $this->localeRepository->update($payload, Context::createDefaultContext())->getErrors()
        );
    }
}
