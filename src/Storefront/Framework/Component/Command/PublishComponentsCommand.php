<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Component\Command;

use Shopware\Core\Framework\Log\Package;
use Shopware\Storefront\Framework\Component\ComponentPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copies built component JS and CSS files from each bundle's
 * `dist-es/components/` into `public/components/` and writes
 * `var/cache/component-manifest.json`.
 *
 * Run once after `npm run build:components` during deployment (with --all),
 * or for a single bundle when debugging.
 *
 * In normal operation this command is called automatically by Shopware's
 * plugin/app lifecycle subscribers whenever a bundle is activated, updated,
 * or deactivated — no manual invocation required.
 */
#[AsCommand(
    name: 'storefront:publish-components',
    description: 'Publishes built component assets to public/components/ and writes the component manifest.',
)]
#[Package('framework')]
class PublishComponentsCommand extends Command
{
    /**
     * @internal
     */
    public function __construct(private readonly ComponentPublisher $componentPublisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'Publish components for all bundles listed in var/plugins.json (default behaviour when no bundle is specified).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Storefront: Publish Components');

        $this->componentPublisher->publishAll();

        $manifest = $this->componentPublisher->readComponentManifest();
        $count = \count($manifest);

        if ($count === 0) {
            $io->note('No component entries found. Make sure `npm run build:components` has been executed first.');
        } else {
            $io->success(\sprintf('Published %d component entr%s to public/components/.', $count, $count === 1 ? 'y' : 'ies'));
        }

        return Command::SUCCESS;
    }
}
