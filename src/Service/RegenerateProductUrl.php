<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Service;

use Exception;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateProductUrl
{
    /**#@+
     * Constants
     */
    public const BATCH_SIZE = 500;
    /**#@-*/

    /**
     * @var OutputInterface|null
     */
    private ?OutputInterface $output = null;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var ?ProductUrlRewriteGenerator
     */
    private ?ProductUrlRewriteGenerator $urlRewriteGenerator = null;

    /**
     * @var UrlPersistInterface
     */
    private UrlPersistInterface $urlPersist;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var int
     */
    private int $regeneratedCount = 0;

    /**
     * @var bool
     */
    private bool $isVerboseMode = false;

    /**
     * Constructor.
     *
     * @param CollectionFactory $collectionFactory
     * @param ProductUrlRewriteGenerator $urlRewriteGenerator
     * @param UrlPersistInterface $urlPersist
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        ProductUrlRewriteGenerator $urlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        StoreManagerInterface $storeManager
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->urlRewriteGenerator = $urlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->storeManager = $storeManager;
    }

    /**
     * Process
     *
     * @param int[]|null $productIds
     * @param int|null $storeId
     * @param bool $verbose
     *
     * @return void
     * @throws NoSuchEntityException
     */
    public function execute(?array $productIds = null, ?int $storeId = null, bool $verbose = false): void
    {
        $this->isVerboseMode = $verbose;
        $this->regeneratedCount = 0;

        $stores = null !== $storeId
            ? [$this->storeManager->getStore($storeId)]
            : $this->storeManager->getStores();

        foreach ($stores as $store) {
            $regeneratedForStore = 0;

            $this->log(sprintf('Start regenerating for store %s (%d)', $store->getName(), $store->getId()));

            $collection = $this->collectionFactory->create();
            $collection
                ->setStoreId($store->getId())
                ->addStoreFilter($store->getId())
                ->addAttributeToSelect('name')
                ->addFieldToFilter('status', ['eq' => Status::STATUS_ENABLED])
                ->addFieldToFilter('visibility', ['gt' => Visibility::VISIBILITY_NOT_VISIBLE]);

            if ($productIds == null || (is_array($productIds) && empty($productIds))) {
                $productIds = $collection->getAllIds();
            }
            $collection->addIdFilter($productIds);

            $collection->addAttributeToSelect(['url_path', 'url_key']);

            $deleteProducts = [];

            /** @var Product $product */
            foreach ($collection as $product) {
                $deleteProducts[] = $product->getId();
                if (count($deleteProducts) >= self::BATCH_SIZE) {
                    $this->deleteUrls($deleteProducts, $store);
                    $deleteProducts = [];
                }
            }

            if (count($deleteProducts)) {
                $this->deleteUrls($deleteProducts, $store, true);
                $deleteProducts = [];
            }

            $newUrls = [];
            /** @var Product $product */
            foreach ($collection as $product) {
                if ($this->isVerboseMode) {
                    $this->log(
                        sprintf(
                            'Regenerating urls for %s (%s) in store (%s)',
                            $product->getSku(),
                            $product->getId(),
                            $store->getName()
                        )
                    );
                }

                $product->setStoreId($store->getId());

                //phpcs:ignore Magento2.Performance.ForeachArrayMerge.ForeachArrayMerge
                $newUrls = array_merge($newUrls, $this->urlRewriteGenerator->generate($product));
                if (count($newUrls) >= self::BATCH_SIZE) {
                    $regeneratedForStore += $this->replaceUrls($newUrls);
                }
            }

            if (count($newUrls)) {
                $regeneratedForStore += $this->replaceUrls($newUrls, true);
            }

            $this->log(
                sprintf(
                    'Done regenerating. Regenerated %d urls for store %s (%d)',
                    $regeneratedForStore,
                    $store->getName(),
                    $store->getId()
                )
            );
            $this->regeneratedCount += $regeneratedForStore;
        }
    }

    /**
     * Generate output
     *
     * @param OutputInterface $output
     *
     * @return void
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * Generate count
     *
     * @return int
     */
    public function getRegeneratedCount(): int
    {
        return $this->regeneratedCount;
    }

    /**
     * Log output
     *
     * @param string $message
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->output !== null) {
            $this->output->writeln($message);
        }
    }

    /**
     * Replace urls
     *
     * @param array $urls
     * @param bool $last
     *
     * @return int
     */
    private function replaceUrls(array &$urls, bool $last = false): int
    {
        $this->log(sprintf('replaceUrls%s batch: %d', $last ? ' last' : '', count($urls)));

        if ($this->isVerboseMode) {
            foreach ($urls as $url) {
                $this->log(sprintf(
                    'Preparing to replace URL: Entity ID %d, Request Path %s',
                    $url->getEntityId(),
                    $url->getRequestPath()
                ));
            }
        }
        
        try {
            $this->urlPersist->replace($urls);
        } catch (Exception $e) {
            // Log errors at batch level, or optionally retry smaller batches
            $this->log($e->getMessage());
        }

        $count = count($urls);
        $urls = [];

        return $count;
    }

    /**
     * Remove old product urls
     *
     * @param array $productIds
     * @param StoreInterface $store
     * @param bool $last
     *
     * @return void
     */
    private function deleteUrls(array $productIds, StoreInterface $store, bool $last = false): void
    {
        $this->log(sprintf('deleteUrls%s batch: %d', $last ? ' last' : '', count($productIds)));
        $this->urlPersist->deleteByData([
            UrlRewrite::ENTITY_ID => $productIds,
            UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
            UrlRewrite::REDIRECT_TYPE => 0,
            UrlRewrite::STORE_ID => $store->getId()
        ]);
    }
}
