<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Console\Command;

use Elgentos\RegenerateCatalogUrls\Service\RegenerateProductUrl;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateProductUrlCommand extends AbstractRegenerateCommand
{
    private State $state;

    private StoreManagerInterface $storeManager;

    private RegenerateProductUrl $regenerateProductUrl;

    /**
     * RegenerateProductUrlCommand constructor.
     * @param State $state
     * @param StoreManagerInterface $storeManager
     * @param RegenerateProductUrl $regenerateProductUrl
     */
    public function __construct(
        State                 $state,
        StoreManagerInterface $storeManager,
        RegenerateProductUrl  $regenerateProductUrl
    ) {
        $this->state = $state;
        $this->storeManager = $storeManager;
        $this->regenerateProductUrl = $regenerateProductUrl;
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('regenerate:product:url')
            ->setDescription('Regenerate url for given products')
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'Product IDs to regenerate'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws LocalizedException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $stores = $this->storeManager->getStores(false);

        $this->regenerateProductUrl->setOutput($output);

        $storeId = $input->getOption('store');
        if ($storeId !== 'all' && !is_null($storeId) && !is_numeric($storeId)) {
            $storeId = $this->getStoreIdByCode($storeId, $stores);
        }

        if (!is_null($storeId) && is_numeric($storeId)) {
            $this->regenerateProductUrl->execute($input->getArgument('pids'), $storeId, $output->isVerbose());
            return 0;
        }

        if ($storeId === 'all') {
            foreach ($stores as $store) {
                $this->regenerateProductUrl->execute($input->getArgument('pids'), $store->getId(), $output->isVerbose());
            }
            return 0;
        }

        throw new LocalizedException(__('No URLs are regenerated - did you pass a store and are you sure the store exists?'));
    }
}
