<?php

declare(strict_types=1);

namespace Iazel\RegenProductUrl\Service;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
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
     * @var Collection\Proxy
     */
    private $collection;
    /**
     * @var ProductUrlRewriteGenerator\Proxy
     */
    private $urlRewriteGenerator;
    /**
     * @var UrlPersistInterface\Proxy
     */
    private $urlPersist;
    /**
     * @var StoreManagerInterface\Proxy
     */
    private $storeManager;
    /**
     * Counter for amount of urls regenerated.
     * @var int
     */
    private $regeneratedCount = 0;

    public function __construct(
        Collection\Proxy $collection,
        ProductUrlRewriteGenerator\Proxy $urlRewriteGenerator,
        UrlPersistInterface\Proxy $urlPersist,
        StoreManagerInterface\Proxy $storeManager
    ) {
        $this->collection = $collection;
        $this->urlRewriteGenerator = $urlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->storeManager = $storeManager;
    }

    /**
     * @param int[] $productIds
     * @param int $storeId
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(array $productIds, int $storeId)
    {
        $this->storeManager->getStore($storeId);
        $this->regeneratedCount = 0;

        $stores = $this->storeManager->getStores(false);
        foreach ($stores as $store) {
            $regeneratedForStore = 0;
            // If store has been given through option, skip other stores
            if ($storeId !== Store::DEFAULT_STORE_ID AND (int) $store->getId() !== $storeId) {
                continue;
            }

            $this->collection
                ->addStoreFilter($store->getId())
                ->setStoreId($store->getId())
                ->addFieldToFilter('visibility', ['gt' => Visibility::VISIBILITY_NOT_VISIBLE]);

            if (!empty($productIds)) {
                $this->collection->addIdFilter($productIds);
            }

            $this->collection->addAttributeToSelect(['url_path', 'url_key']);
            $list = $this->collection->load();

            /** @var \Magento\Catalog\Model\Product $product */
            foreach ($list as $product) {
                $this->log('Regenerating urls for ' . $product->getSku() . ' (' . $product->getId() . ') in store ' . $store->getName());
                $product->setStoreId($store->getId());

                $this->urlPersist->deleteByData([
                    UrlRewrite::ENTITY_ID => $product->getId(),
                    UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                    UrlRewrite::REDIRECT_TYPE => 0,
                    UrlRewrite::STORE_ID => $store->getId()
                ]);

                $newUrls = $this->urlRewriteGenerator->generate($product);
                try {
                    $this->urlPersist->replace($newUrls);
                    $regeneratedForStore += count($newUrls);
                } catch (\Exception $e) {
                    $this->log(sprintf('<error>Duplicated url for store ID %d, product %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store->getId(), $product->getId(), $product->getSku(), $e->getMessage(), implode(PHP_EOL, array_keys($newUrls))));
                }
            }
            $this->log('Done regenerating. Regenerated ' . $regeneratedForStore . ' urls for store ' . $store->getName());
            $this->regeneratedCount += $regeneratedForStore;
        }
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function getRegeneratedCount(): int
    {
        return $this->regeneratedCount;
    }

    private function log(string $message)
    {
        if ($this->output !== null) {
            $this->output->writeln($message);
        }
    }
}
