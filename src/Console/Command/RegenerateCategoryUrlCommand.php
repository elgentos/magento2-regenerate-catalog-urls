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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\State;

class RegenerateCategoryUrlCommand extends AbstractRegenerateCommand
{
    /**
     * @var CategoryUrlRewriteGenerator
     */
    private CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    private UrlPersistInterface $urlPersist;

    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var ?Emulation
     */
    private ?Emulation $emulation = null;

    /**
     * @param StoreManagerInterface $storeManager
     * @param State $state
     * @param RegenerateProductUrl $regenerateProductUrl
     * @param QuestionHelper $questionHelper
     * @param CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator
     * @param UrlPersistInterface $urlPersist
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param Emulation $emulation
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        State $state,
        RegenerateProductUrl $regenerateProductUrl,
        QuestionHelper $questionHelper,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        CategoryCollectionFactory $categoryCollectionFactory,
        Emulation $emulation
    ) {
        parent::__construct($storeManager, $state, $regenerateProductUrl, $questionHelper);
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->emulation = $emulation;
    }

    /**
     * @inheritdoc
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
     * @inheritdoc
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

        $rootIdOption = (int)$input->getOption('root') ?: false;

        foreach ($stores as $storeId) {
            $currentRootId = $this->storeManager->getGroup(
                $this->storeManager->getStore($storeId)->getStoreGroupId()
            )->getRootCategoryId();
            if ($rootIdOption !== false) {
                $fromRootId = $rootIdOption;
                if ($rootIdOption !== $currentRootId) {
                    $output->writeln(
                        sprintf(
                            'Skipping store %s because its root category id %s, differs from the passed root category %s', //phpcs:ignore Generic.Files.LineLength.TooLong
                            $storeId,
                            $currentRootId,
                            $fromRootId
                        )
                    );
                    continue;
                }
            } else {
                $fromRootId = $currentRootId;
            }

            $output->writeln(
                sprintf('Processing store %s...', $storeId)
            );

            $rootCategory = $this->categoryCollectionFactory->create()->addAttributeToFilter(
                'entity_id',
                $fromRootId
            )->addAttributeToSelect("name")->getFirstItem();
            if (!$rootCategory->getId()) {
                throw new Exception(sprintf("Root category with ID %d, was not found.", $fromRootId));
            }
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

            $categories = $this->categoryCollectionFactory->create()
                ->setStore($storeId)
                ->addAttributeToSelect(['name', 'url_path', 'url_key', 'path'])
                ->addAttributeToFilter('level', ['gt' => 1]);

            $categoryIds = $input->getArgument('cids');
            if ($fromRootId) {
                //path LIKE '1/rootcategory/%' OR path = '1/rootcategory'
                $categories->addAttributeToFilter('path', [
                    'like' => '1/' . $fromRootId . '/%',
                    '=' => '1/' . $fromRootId
                ]);
            }
            if (!empty($categoryIds)) {
                $categories->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
            }

            foreach ($categories as $category) {
                $output->writeln(
                    sprintf(
                        'Regenerating urls for %s (%s)',
                        implode('/', [
                            $rootCategory->getName(),
                            ...array_map(fn ($category) => $category->getName(), $category->getParentCategories())
                        ]),
                        $category->getId()
                    )
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
