<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Console\Command;

use Elgentos\RegenerateCatalogUrls\Service\RegenerateProductUrl;
use Exception;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\CmsUrlRewrite\Model\CmsPageUrlRewriteGenerator;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateCmsPageUrlCommand extends AbstractRegenerateCommand
{
    private Emulation $emulation;

    private PageCollectionFactory $pageCollectionFactory;

    private UrlPersistInterface $urlPersist;

    private CmsPageUrlRewriteGenerator $cmsPageUrlRewriteGenerator;

    public function __construct(StoreManagerInterface      $storeManager,
                                State                      $state,
                                RegenerateProductUrl       $regenerateProductUrl,
                                QuestionHelper             $questionHelper,
                                Emulation                  $emulation,
                                PageCollectionFactory      $pageCollectionFactory,
                                UrlPersistInterface        $urlPersist,
                                CmsPageUrlRewriteGenerator $cmsPageUrlRewriteGenerator
    )
    {
        parent::__construct($storeManager, $state, $regenerateProductUrl, $questionHelper);
        $this->emulation = $emulation;
        $this->pageCollectionFactory = $pageCollectionFactory;
        $this->urlPersist = $urlPersist;
        $this->cmsPageUrlRewriteGenerator = $cmsPageUrlRewriteGenerator;
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('regenerate:cms-page:url')
            ->setDescription('Regenerate url for cms pages.')
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'CMS Pages to regenerate'
            );

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws LocalizedException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $output->writeln('<info>Start regenerating urls for CMS pages.</info>');

        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        }

        $counter = 0;

        $stores = $this->getChosenStores();

        foreach ($stores as $storeId) {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

            $pages = $this->pageCollectionFactory->create();

            $pages->addStoreFilter($storeId);

            if (count($input->getArgument('pids')) > 0) {
                $pageIds = $input->getArgument('pids');
            } else {
                $pageIds = $pages->getAllIds();
            }
            $pageIds = array_unique($pageIds);
            $pages->addFieldToFilter('page_id', ['in' => $pageIds]);

            /** @var Page $page */
            foreach ($pages as $page) {
                $newUrls = $this->cmsPageUrlRewriteGenerator->generate($page);

                try {
                    $this->urlPersist->replace($newUrls);
                    $counter += count($newUrls);
                } catch (UrlAlreadyExistsException $e) {
                    $output->writeln(
                        sprintf(
                            '<error>Url for page %s (%d) already exists.' . PHP_EOL . '%s</error>',
                            $page->getTitle(),
                            $page->getId(),
                            $e->getMessage()
                        )
                    );
                } catch (Exception $e) {
                    $output->writeln(
                        '<error>Couldn\'t replace url for %s (%d)' . PHP_EOL . '%s</error>'
                    );
                }
            }

            $this->emulation->stopEnvironmentEmulation();
            $output->writeln(
                sprintf(
                    '<info>Finished regenerating. Regenerated %d urls.</info>',
                    $counter
                )
            );
        }

        return Cli::RETURN_SUCCESS;
    }
}
