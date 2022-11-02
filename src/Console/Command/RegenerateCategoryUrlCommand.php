<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Console\Command;

use Elgentos\RegenerateCatalogUrls\Service\RegenerateProductUrl;
use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\State;

class RegenerateCategoryUrlCommand extends AbstractRegenerateCommand
{
    private CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator;

    private UrlPersistInterface $urlPersist;

    private CategoryCollectionFactory $categoryCollectionFactory;

    private Emulation $emulation;

    public function __construct(
        StoreManagerInterface       $storeManager,
        State                       $state,
        RegenerateProductUrl        $regenerateProductUrl,
        QuestionHelper              $questionHelper,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface         $urlPersist,
        CategoryCollectionFactory   $categoryCollectionFactory,
        Emulation                   $emulation
    ) {
        parent::__construct($storeManager, $state, $regenerateProductUrl, $questionHelper);
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->emulation = $emulation;
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('regenerate:category:url')
            ->setDescription('Regenerate url for given categories')
            ->addArgument(
                'cids',
                InputArgument::IS_ARRAY,
                'Categories to regenerate'
            )->addOption(
                'root',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Regenerate for root category and its children only',
                false
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
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $counter = 0;

        $stores = $this->getChosenStores();

        foreach ($stores as $storeId) {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

            $categories = $this->categoryCollectionFactory->create()
                ->setStore($storeId)
                ->addAttributeToSelect(['name', 'url_path', 'url_key', 'path'])
                ->addAttributeToFilter('level', ['gt' => 1]);

            $fromRootId = intval($input->getOption('root')) ?? 0;
            $categoryIds = $input->getArgument('cids');
            if ($fromRootId) {
                //path LIKE '1/rootcategory/%' OR path = '1/rootcategory'
                $categories->addAttributeToFilter('path', [
                    'like' => '1/' . $fromRootId . '/%',
                    '='    => '1/' . $fromRootId
                ]);
            }
            else if (!empty($categoryIds)) {
                $categories->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
            }

            foreach ($categories as $category) {
                $output->writeln(
                    sprintf('Regenerating urls for %s (%s)', $category->getName(), $category->getId())
                );

                $this->urlPersist->deleteByData(
                    [
                        UrlRewrite::ENTITY_ID => $category->getId(),
                        UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
                        UrlRewrite::REDIRECT_TYPE => 0,
                        UrlRewrite::STORE_ID => $storeId
                    ]
                );

                $newUrls = $this->categoryUrlRewriteGenerator->generate($category);

                try {
                    $newUrls = $this->filterEmptyRequestPaths($newUrls);
                    $this->urlPersist->replace($newUrls);
                    $counter += count($newUrls);
                } catch (Exception $e) {
                    $output->writeln(
                        sprintf(
                            '<error>Duplicated url for store ID %d, category %d (%s) - %s Generated URLs:' .
                            PHP_EOL . '%s</error>' . PHP_EOL,
                            $storeId,
                            $category->getId(),
                            $category->getName(),
                            $e->getMessage(),
                            implode(PHP_EOL, array_keys($newUrls))
                        )
                    );
                }
            }

            $this->emulation->stopEnvironmentEmulation();
        }
        $output->writeln(
            sprintf('Done regenerating. Regenerated %d urls', $counter)
        );

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Remove entries with request_path='' to prevent error 404 for "http://site.com/" address.
     *
     * @param UrlRewrite[] $newUrls
     *
     * @return UrlRewrite[]
     */
    private function filterEmptyRequestPaths(array $newUrls): array
    {
        $result = [];

        foreach ($newUrls as $key => $url) {
            $requestPath = $url->getRequestPath();

            if (!empty($requestPath)) {
                $result[$key] = $url;
            }
        }

        return $result;
    }
}
