<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\EventManager;
use Magento\Store\Model\App\Emulation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateCategoryPathCommand extends Command
{
    /**
     * @var CategoryUrlPathGenerator\Proxy
     */
    protected $categoryUrlPathGenerator;

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
     * @var EventManager\Proxy
     */
    private $eventManager;
    /**
     * @var CategoryResource\Proxy
     */
    private $categoryResource;
    /**
     * @var Emulation\Proxy
     */
    private $emulation;

    /**
     * RegenerateCategoryPathCommand constructor.
     * @param State $state
     * @param CategoryCollectionFactory\Proxy $categoryCollectionFactory
     * @param CategoryUrlPathGenerator\Proxy $categoryUrlPathGenerator
     * @param UrlPersistInterface\Proxy $urlPersist
     * @param EventManager\Proxy $eventManager
     * @param CategoryResource\Proxy $categoryResource
     * @param ResourceConnection\Proxy $resource
     * @param Emulation\Proxy $emulation
     */
    public function __construct(
        State $state,
        CategoryCollectionFactory\Proxy $categoryCollectionFactory,
        CategoryUrlPathGenerator\Proxy $categoryUrlPathGenerator,
        UrlPersistInterface\Proxy $urlPersist,
        EventManager\Proxy $eventManager,
        CategoryResource\Proxy $categoryResource,
        Emulation\Proxy $emulation
    ) {
        $this->state = $state;
        $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
        $this->urlPersist = $urlPersist;
        parent::__construct();
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->eventManager = $eventManager;
        $this->categoryResource = $categoryResource;
        $this->emulation = $emulation;
    }

    protected function configure()
    {
        $this->setName('regenerate:category:path')
            ->setDescription('Regenerate path for given categories')
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

            $category->setOrigData('url_key', mt_rand(1,1000)); // set url_key in orig data to random value to force regeneration of path
            $category->setOrigData('url_path', mt_rand(1,1000)); // set url_path in orig data to random value to force regeneration of path for children

            // Make use of Magento's event for this
            $this->emulation->startEnvironmentEmulation($store_id, Area::AREA_FRONTEND, true);
            $this->eventManager->dispatch('regenerate_category_url_path', ['category' => $category]);
            $this->emulation->stopEnvironmentEmulation();

            $regenerated++;
        }

        $out->writeln('Done regenerating. Regenerated url paths for ' . $regenerated . ' categories');
    }
}
