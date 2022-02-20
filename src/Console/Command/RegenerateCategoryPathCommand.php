<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Console\Command;

use Elgentos\RegenerateCatalogUrls\Service\RegenerateProductUrl;
use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\Console\Cli;
use Magento\Framework\EntityManager\EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\State;

class RegenerateCategoryPathCommand extends AbstractRegenerateCommand
{
    private CategoryCollectionFactory $categoryCollectionFactory;

    private EventManager $eventManager;

    private Emulation $emulation;

    public function __construct(
        StoreManagerInterface     $storeManager,
        State                     $state,
        RegenerateProductUrl      $regenerateProductUrl,
        QuestionHelper            $questionHelper,
        CategoryCollectionFactory $categoryCollectionFactory,
        EventManager              $eventManager,
        Emulation                 $emulation
    ) {
        parent::__construct($storeManager, $state, $regenerateProductUrl, $questionHelper);
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->eventManager = $eventManager;
        $this->emulation = $emulation;
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
            );

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws LocalizedException
     * @throws Exception
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $stores = $this->getChosenStores();

        foreach ($stores as $storeId) {
            $categories = $this->categoryCollectionFactory->create()
                ->setStore($storeId)
                ->addAttributeToSelect(['name', 'url_path', 'url_key'])
                ->addAttributeToFilter('level', ['gt' => 1]);

            $categoryIds = $input->getArgument('cids');

            if (!empty($categoryIds)) {
                $categories->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
            }

            $counter = 0;

            foreach ($categories as $category) {
                $output->writeln(
                    sprintf('Regenerating paths for %s (%s)', $category->getName(), $category->getId())
                );

                // set url_key in orig data to random value to force regeneration of path
                $category->setOrigData('url_key', random_int(1, 1000));

                // set url_path in orig data to random value to force regeneration of path for children
                $category->setOrigData('url_path', random_int(1, 1000));

                // Make use of Magento's event for this
                $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
                $this->eventManager->dispatch('regenerate_category_url_path', ['category' => $category]);
                $this->emulation->stopEnvironmentEmulation();

                $counter++;
            }

            $output->writeln(
                sprintf('Done regenerating. Regenerated url paths for %d categories', $counter)
            );
        }

        return Cli::RETURN_SUCCESS;
    }
}
