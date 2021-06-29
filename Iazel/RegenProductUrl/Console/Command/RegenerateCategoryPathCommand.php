<?php

namespace Iazel\RegenProductUrl\Console\Command;

use Iazel\RegenProductUrl\Model\CategoryUrlPathGenerator;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\App\Area;
use Magento\Framework\EntityManager\EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateCategoryPathCommand extends Command
{
    /**
     * @var CategoryUrlPathGenerator
     */
    protected $categoryUrlPathGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $collection;

    /**
     * @var State
     */
    protected $state;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * @var Emulation
     */
    private $emulation;

    /**
     * RegenerateCategoryPathCommand constructor.
     *
     * @param State                     $state
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param CategoryUrlPathGenerator  $categoryUrlPathGenerator
     * @param UrlPersistInterface       $urlPersist
     * @param EventManager              $eventManager
     * @param Emulation                 $emulation
     */
    public function __construct(
        State $state,
        CategoryCollectionFactory $categoryCollectionFactory,
        CategoryUrlPathGenerator $categoryUrlPathGenerator,
        UrlPersistInterface $urlPersist,
        EventManager $eventManager,
        Emulation $emulation
    ) {
        $this->state                     = $state;
        $this->categoryUrlPathGenerator  = $categoryUrlPathGenerator;
        $this->urlPersist                = $urlPersist;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->eventManager              = $eventManager;
        $this->emulation                 = $emulation;

        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('regenerate:category:path')
            ->setDescription('Regenerate path for given categories')
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

            // set url_key in orig data to random value to force regeneration of path
            $category->setOrigData('url_key', mt_rand(1, 1000));

            // set url_path in orig data to random value to force regeneration of path for children
            $category->setOrigData('url_path', mt_rand(1, 1000));

            // Make use of Magento's event for this
            $this->emulation->startEnvironmentEmulation($store_id, Area::AREA_FRONTEND, true);
            $this->eventManager->dispatch('regenerate_category_url_path', ['category' => $category]);
            $this->emulation->stopEnvironmentEmulation();

            $counter++;
        }

        $output->writeln(
            sprintf('Done regenerating. Regenerated url paths for %d categories', $counter)
        );
    }
}
