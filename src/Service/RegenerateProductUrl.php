<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Service;

use Exception;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateProductUrl
{
    public const BATCH_SIZE = 500;

    private ?OutputInterface $output = null;

    private CollectionFactory $collectionFactory;

    private ProductUrlRewriteGenerator $urlRewriteGenerator;

    private UrlPersistInterface $urlPersist;

    private StoreManagerInterface $storeManager;

    private int $regeneratedCount = 0;

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
        ProductUrlRewriteGenerator\Proxy $urlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        StoreManagerInterface $storeManager
    ) {
        $this->collectionFactory   = $collectionFactory;
        $this->urlRewriteGenerator = $urlRewriteGenerator;
        $this->urlPersist          = $urlPersist;
        $this->storeManager        = $storeManager;
    }

    /**
     * @param int[]|null $productIds
     * @param int|null $storeId
     * @param bool $verbose
     *
     * @return void
     * @throws NoSuchEntityException
     */
    public function execute(?array $productIds = null, ?int $storeId = null, bool $verbose = false): void
    {
        $this->regeneratedCount = 0;

        $stores = !is_null($storeId)
            ? [$this->storeManager->getStore($storeId)]
            : $this->storeManager->getStores(false);

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

            if (is_null($productIds) || (is_array($productIds) && empty($productIds))) {
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
            try {
                /** @var Product $product */
                foreach ($collection as $product) {
                    if ($verbose) {
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

                    $newUrls = array_merge($newUrls, $this->urlRewriteGenerator->generate($product));
                    if (count($newUrls) >= self::BATCH_SIZE) {
                        $regeneratedForStore += $this->replaceUrls($newUrls);
                    }
                }

                if (count($newUrls)) {
                    $regeneratedForStore += $this->replaceUrls($newUrls, true);
                }
            } catch (\Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException $e) {
                $this->log(sprintf(
                    '<error>Couldn\'t insert duplicate URL rewrites for the following ' .
                    'products on store ID %d (current batch failed):' . PHP_EOL . '%s</error>',
                    $store->getId(),
                    implode(PHP_EOL, array_map(function ($url) {
                        return sprintf(
                            '- Product ID: %d, request path: %s',
                            $url['entity_id'],
                            $url['request_path']
                        );
                    }, $e->getUrls()))
                ));
            } catch (Exception $e) {
                $this->log(
                    sprintf(
                        '<error>Duplicated url for store ID %d, product %d (%s) - %s Generated URLs:' .
                        PHP_EOL . '%s</error>' . PHP_EOL,
                        $store->getId(),
                        $product->getId(),
                        $product->getSku(),
                        $e->getMessage(),
                        implode(PHP_EOL, array_keys($newUrls))
                    )
                );
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
     * @param OutputInterface $output
     *
     * @return void
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * @return int
     */
    public function getRegeneratedCount(): int
    {
        return $this->regeneratedCount;
    }

    /**
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
     * Add product ulrs
     *
     * @param array $urls
     * @param bool $last
     *
     * @return int
     * @throws UrlAlreadyExistsException
     */
    private function replaceUrls(array &$urls, bool $last = false): int
    {
        $this->log(sprintf('replaceUrls%s batch: %d', $last ? ' last' : '', count($urls)));

        foreach ($urls as $url) {
            try {
                $this->urlPersist->replace([$url]);
            } catch (UrlAlreadyExistsException $e) {
                $this->log(sprintf($e->getMessage(). ' Entity id: %d Request path: %s',
                    $url->getEntityId(),
                    $url->getRequestPath()
                ));
            }
        }
        $count = count($urls);
        $urls  = [];

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
    private function deleteUrls(array &$productIds, StoreInterface $store, bool $last = false): void
    {
        $this->log(sprintf('deleteUrls%s batch: %d', $last ? 'last' : '', count($productIds)));
        $this->urlPersist->deleteByData([
            UrlRewrite::ENTITY_ID     => $productIds,
            UrlRewrite::ENTITY_TYPE   => ProductUrlRewriteGenerator::ENTITY_TYPE,
            UrlRewrite::REDIRECT_TYPE => 0,
            UrlRewrite::STORE_ID      => $store->getId()
        ]);
    }
}
