<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateCategoryUrlCommand extends Command
{
    /**
     * @var CategoryUrlRewriteGenerator\Proxy
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface\Proxy
     */
    protected $urlPersist;

    /**
     * @var CategoryRepositoryInterface\Proxy
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;
    
    /**
     * @var CategoryCollectionFactory\Proxy
     */
    private $categoryCollectionFactory;
    
    /**
     * @var Emulation\Proxy
     */
    private $emulation;

    /**
     * RegenerateCategoryUrlCommand constructor.
     * @param State $state
     * @param Collection\Proxy $collection
     * @param CategoryUrlRewriteGenerator\Proxy $categoryUrlRewriteGenerator
     * @param UrlPersistInterface\Proxy $urlPersist
     * @param CategoryCollectionFactory\Proxy $categoryCollectionFactory
     * @param Emulation\Proxy $emulation
     */
    public function __construct(
        State $state,
        Collection\Proxy $collection,
        CategoryUrlRewriteGenerator\Proxy $categoryUrlRewriteGenerator,
        UrlPersistInterface\Proxy $urlPersist,
        CategoryCollectionFactory\Proxy $categoryCollectionFactory,
        Emulation\Proxy $emulation
    ) {
        $this->state = $state;
        $this->collection = $collection;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->categoryCollectionFactory = $categoryCollectionFactory;

        parent::__construct();
        $this->emulation = $emulation;
    }

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
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                Store::DEFAULT_STORE_ID
            )
        ;
        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {
        try{
            $this->state->getAreaCode();
        }catch ( \Magento\Framework\Exception\LocalizedException $e){
            $this->state->setAreaCode('adminhtml');
        }

        $store_id = $inp->getOption('store');
        $this->emulation->startEnvironmentEmulation($store_id, Area::AREA_FRONTEND, true);

        $categories = $this->categoryCollectionFactory->create()
            ->setStore($store_id)
            ->addAttributeToSelect(['name', 'url_path', 'url_key']);

        $cids = $inp->getArgument('cids');
        if( !empty($cids) ) {
            $categories->addAttributeToFilter('entity_id', ['in' => $cids]);
        }

        $regenerated = 0;
        foreach($categories as $category)
        {
            $out->writeln('Regenerating urls for ' . $category->getName() . ' (' . $category->getId() . ')');

            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID => $category->getId(),
                UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
                UrlRewrite::REDIRECT_TYPE => 0,
                UrlRewrite::STORE_ID => $store_id
            ]);

            $newUrls = $this->categoryUrlRewriteGenerator->generate($category);
            try {
                $newUrls = $this->filterEmptyRequestPaths($newUrls);
                $this->urlPersist->replace($newUrls);
                $regenerated += count($newUrls);
            }
            catch(\Exception $e) {
                $out->writeln(sprintf('<error>Duplicated url for store ID %d, category %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store_id, $category->getId(), $category->getName(), $e->getMessage(), implode(PHP_EOL, array_keys($newUrls))));
            }
        }
        $this->emulation->stopEnvironmentEmulation();
        $out->writeln('Done regenerating. Regenerated ' . $regenerated . ' urls');
    }
    
    /**
     * Remove entries with request_path='' to prevent error 404 for "http://site.com/" address.
     *
     * @param \Magento\UrlRewrite\Service\V1\Data\UrlRewrite[] $newUrls
     * @return \Magento\UrlRewrite\Service\V1\Data\UrlRewrite[]
     */
    private function filterEmptyRequestPaths($newUrls)
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
