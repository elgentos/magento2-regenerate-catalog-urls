<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateProductUrlCommand extends Command
{
    /**
     * @var ProductUrlRewriteGenerator\Proxy
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface\Proxy
     */
    protected $urlPersist;

    /**
     * @var Collection\Proxy
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;
    
    /**
     * @var StoreManagerInterface\Proxy
     */
    private $storeManager;

    /**
     * RegenerateProductUrlCommand constructor.
     * @param State $state
     * @param Collection\Proxy $collection
     * @param ProductUrlRewriteGenerator\Proxy $productUrlRewriteGenerator
     * @param UrlPersistInterface\Proxy $urlPersist
     * @param StoreManagerInterface\Proxy $storeManager
     */
    public function __construct(
        State $state,
        Collection\Proxy $collection,
        ProductUrlRewriteGenerator\Proxy $productUrlRewriteGenerator,
        UrlPersistInterface\Proxy $urlPersist,
        StoreManagerInterface\Proxy $storeManager
    ) {
        $this->state = $state;
        $this->collection = $collection;
        $this->productUrlRewriteGenerator = $productUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        parent::__construct();
        $this->storeManager = $storeManager;
    }

    protected function configure()
    {
        $this->setName('regenerate:product:url')
            ->setDescription('Regenerate url for given products')
            ->addOption(
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Regenerate for one specific store view',
                Store::DEFAULT_STORE_ID
            )
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'Product IDs to regenerate'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $storeId = $input->getOption('store');
        $stores = $this->storeManager->getStores(false);

        if (!is_numeric($storeId)) {
            $storeId = $this->getStoreIdByCode($storeId, $stores);
        }

        if (!is_numeric($storeId)) {
            throw new \Exception('Store could not be found. Please enter a store ID or a store code.');
        } else {
            $this->storeManager->getStore($storeId);
        }

        foreach ($stores as $store) {
            // If store has been given through option, skip other stores
            if ($storeId != Store::DEFAULT_STORE_ID AND $store->getId() != $storeId) {
                continue;
            }

            $this->collection
                ->addStoreFilter($store->getId())
                ->setStoreId($store->getId())
                ->addFieldToFilter('visibility', ['gt' => Visibility::VISIBILITY_NOT_VISIBLE]);

            $pids = $input->getArgument('pids');
            if (!empty($pids)) {
                $this->collection->addIdFilter($pids);
            }

            $this->collection->addAttributeToSelect(['url_path', 'url_key']);
            $list = $this->collection->load();
            $regenerated = 0;

            /** @var \Magento\Catalog\Model\Product $product */
            foreach ($list as $product) {
                echo 'Regenerating urls for ' . $product->getSku() . ' (' . $product->getId() . ') in store ' . $store->getName() . PHP_EOL;
                $product->setStoreId($store->getId());

                $this->urlPersist->deleteByData([
                    UrlRewrite::ENTITY_ID => $product->getId(),
                    UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                    UrlRewrite::REDIRECT_TYPE => 0,
                    UrlRewrite::STORE_ID => $store->getId()
                ]);

                $newUrls = $this->productUrlRewriteGenerator->generate($product);
                try {
                    $this->urlPersist->replace($newUrls);
                    $regenerated += count($newUrls);
                } catch (\Exception $e) {
                    $output->writeln(sprintf('<error>Duplicated url for store ID %d, product %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store->getId(), $product->getId(), $product->getSku(), $e->getMessage(), implode(PHP_EOL, array_keys($newUrls))));
                }
            }
            $output->writeln('Done regenerating. Regenerated ' . $regenerated . ' urls for store ' . $store->getName());
        }
    }

    private function getStoreIdByCode($store_id, $stores)
    {
        foreach ($stores as $store) {
            if ($store->getCode() == $store_id) {
                return $store->getId();
            }
        }

        return false;
    }
}
