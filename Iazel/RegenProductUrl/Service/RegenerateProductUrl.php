<?php

declare(strict_types=1);

namespace Iazel\RegenProductUrl\Service;

use Exception;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateProductUrl
{
    /**
     * @var OutputInterface|null
     */
    private $output;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var ProductUrlRewriteGenerator
     */
    private $urlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    private $urlPersist;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Counter for amount of urls regenerated.
     * @var int
     */
    private $regeneratedCount = 0;

    /**
     * Constructor.
     *
     * @param CollectionFactory          $collectionFactory
     * @param ProductUrlRewriteGenerator $urlRewriteGenerator
     * @param UrlPersistInterface        $urlPersist
     * @param StoreManagerInterface      $storeManager
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        ProductUrlRewriteGenerator $urlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        StoreManagerInterface $storeManager
    ) {
        $this->collectionFactory   = $collectionFactory;
        $this->urlRewriteGenerator = $urlRewriteGenerator;
        $this->urlPersist          = $urlPersist;
        $this->storeManager        = $storeManager;
    }

    /**
     * @param int[] $productIds
     * @param int   $storeId
     *
     * @return void
     */
    public function execute(array $productIds, int $storeId): void
    {
        $this->regeneratedCount = 0;
        $stores                 = $this->storeManager->getStores(false);

        foreach ($stores as $store) {
            $regeneratedForStore = 0;

            // If store has been given through option, skip other stores
            if ($storeId !== Store::DEFAULT_STORE_ID and (int) $store->getId() !== $storeId) {
                continue;
            }

            $collection = $this->collectionFactory->create();
            $collection
                ->setStoreId($store->getId())
                ->addStoreFilter($store->getId())
                ->addAttributeToSelect('name')
                ->addFieldToFilter('status', ['eq' => Status::STATUS_ENABLED])
                ->addFieldToFilter('visibility', ['gt' => Visibility::VISIBILITY_NOT_VISIBLE]);

            if (!empty($productIds)) {
                $collection->addIdFilter($productIds);
            }

            $collection->addAttributeToSelect(['url_path', 'url_key']);
            $list = $collection->load();

            /** @var Product $product */
            foreach ($list as $product) {
                $this->log(
                    sprintf(
                        'Regenerating urls for %s (%s) in store (%s)',
                        $product->getSku(),
                        $product->getId(),
                        $store->getName()
                    )
                );
                $product->setStoreId($store->getId());

                $this->urlPersist->deleteByData(
                    [
                        UrlRewrite::ENTITY_ID => $product->getId(),
                        UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                        UrlRewrite::REDIRECT_TYPE => 0,
                        UrlRewrite::STORE_ID => $store->getId()
                    ]
                );

                $newUrls = $this->urlRewriteGenerator->generate($product);
                try {
                    $this->urlPersist->replace($newUrls);
                    $regeneratedForStore += count($newUrls);
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
            }

            $this->log(
                sprintf(
                    'Done regenerating. Regenerated %d urls for store %s',
                    $regeneratedForStore,
                    $store->getName()
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
}
