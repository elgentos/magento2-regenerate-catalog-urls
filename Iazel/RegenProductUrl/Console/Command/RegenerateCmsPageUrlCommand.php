<?php


namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\CmsUrlRewrite\Model\CmsPageUrlRewriteGenerator;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateCmsPageUrlCommand extends Command
{
    /**
     * @var State
     */
    private $state;

    /**
     * @var Emulation\Proxy
     */
    private $emulation;

    /**
     * @var PageCollectionFactory\Proxy
     */
    private $pageCollectionFactory;

    /**
     * @var UrlPersistInterface\Proxy
     */
    private $urlPersist;

    /**
     * @var CmsPageUrlRewriteGenerator\Proxy
     */
    private $cmsPageUrlRewriteGenerator;

    /**
     * RegenerateCmsPageUrlCommand constructor.
     *
     * @param State $state
     * @param Emulation\Proxy $emulation
     * @param PageCollectionFactory\Proxy $pageCollectionFactory
     * @param UrlPersistInterface\Proxy $urlPersist
     * @param CmsPageUrlRewriteGenerator\Proxy $cmsPageUrlRewriteGenerator
     */
    public function __construct(
        State $state,
        Emulation\Proxy $emulation,
        PageCollectionFactory\Proxy $pageCollectionFactory,
        UrlPersistInterface\Proxy $urlPersist,
        CmsPageUrlRewriteGenerator\Proxy $cmsPageUrlRewriteGenerator
    ) {
        parent::__construct();
        $this->state = $state;
        $this->emulation = $emulation;
        $this->pageCollectionFactory = $pageCollectionFactory;
        $this->urlPersist = $urlPersist;
        $this->cmsPageUrlRewriteGenerator = $cmsPageUrlRewriteGenerator;
    }

    protected function configure()
    {
        $this->setName('regenerate:cms-page:url')
            ->setDescription('Regenerate url for cms pages.')
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'CMS Pages to regenerate'
            )
            ->addOption(
                'store',
                's',
                InputOption::VALUE_OPTIONAL,
                'Regenerate for one specific store view',
                Store::DEFAULT_STORE_ID
            )->addOption(
                'all-stores',
                'a',
                InputOption::VALUE_OPTIONAL,
                'Regenerate for all stores.',
                false
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int|void|null
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Start regenerating urls for CMS pages.</info>');
        try {
            $this->state->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        }

        $storeId = $input->getOption('store');
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        $pages = $this->pageCollectionFactory->create();

        if (!$input->getOption('all-stores') !== false) {
            $pages->addStoreFilter($storeId);
        }

        if (count($input->getArgument('pids')) > 0) {
            $pageIds = $input->getArgument('pids');
            $pages->addFieldToFilter('page_id', ['in' => $pageIds]);
        }

        $regenerated = 0;
        /** @var Page $page */
        foreach ($pages as $page) {
            $newUrls = $this->cmsPageUrlRewriteGenerator->generate($page);
            try {
                $this->urlPersist->replace($newUrls);
                $regenerated += count($newUrls);
            } catch (UrlAlreadyExistsException $e) {
                $output->writeln(sprintf(
                    '<error>Url for page %s (%d) already exists.' . PHP_EOL . '%s</error>',
                    $page->getTitle(),
                    $page->getId(),
                    $e->getMessage()
                ));
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>Couldn\'t replace url for %s (%d)' . PHP_EOL . '%s</error>'));
            }
        }

        $this->emulation->stopEnvironmentEmulation();
        $output->writeln(sprintf('<info>Finished regenerating. Regenerated %d urls.</info>', $regenerated));
        return Cli::RETURN_SUCCESS;
    }
}
