<?php

namespace Iazel\RegenProductUrl\Console\Command;

use Exception;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateCategoryUrlCommand extends Command
{
    /**
     * @var CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var State
     */
    protected $state;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var Emulation
     */
    private $emulation;

    /**
     * RegenerateCategoryUrlCommand constructor.
     *
     * @param State                       $state
     * @param CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator
     * @param UrlPersistInterface         $urlPersist
     * @param CategoryCollectionFactory   $categoryCollectionFactory
     * @param Emulation                   $emulation
     */
    public function __construct(
        State $state,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        CategoryCollectionFactory $categoryCollectionFactory,
        Emulation $emulation
    ) {
        $this->state                       = $state;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist                  = $urlPersist;
        $this->categoryCollectionFactory   = $categoryCollectionFactory;
        $this->emulation                   = $emulation;

        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('regenerate:category:url')
            ->setDescription('Regenerate url for given categories')
            ->addArgument(
                'cids',
                InputArgument::IS_ARRAY,
                'Categories to regenerate'
            )
            ->addOption(
                'store',
                's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                Store::DEFAULT_STORE_ID
            );

        parent::configure();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void|int
     * @throws LocalizedException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $store_id = $input->getOption('store');
        $this->emulation->startEnvironmentEmulation($store_id, Area::AREA_FRONTEND, true);

        $categories = $this->categoryCollectionFactory->create()
            ->setStore($store_id)
            ->addAttributeToSelect(['name', 'url_path', 'url_key'])
            ->addAttributeToFilter('level', ['gt' => 1]);

        $categoryIds = $input->getArgument('cids');

        if (!empty($categoryIds)) {
            $categories->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
        }

        $counter = 0;

        foreach ($categories as $category) {
            $output->writeln(
                sprintf('Regenerating urls for %s (%s)', $category->getName(), $category->getId())
            );

            $this->urlPersist->deleteByData(
                [
                    UrlRewrite::ENTITY_ID => $category->getId(),
                    UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
                    UrlRewrite::REDIRECT_TYPE => 0,
                    UrlRewrite::STORE_ID => $store_id
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
                        $store_id,
                        $category->getId(),
                        $category->getName(),
                        $e->getMessage(),
                        implode(PHP_EOL, array_keys($newUrls))
                    )
                );
            }
        }

        $this->emulation->stopEnvironmentEmulation();
        $output->writeln(
            sprintf('Done regenerating. Regenerated %d urls', $counter)
        );
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
