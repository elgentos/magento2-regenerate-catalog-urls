<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Console\Command;

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

abstract class AbstractRegenerateCommand extends Command
{
    protected function configure()
    {
        $this->addOption(
            'store',
            's',
            InputOption::VALUE_REQUIRED,
            'Regenerate for one specific store view'
        );
    }


    /**
     * @param string $storeId
     * @param array $stores
     *
     * @return null|int
     */
    protected function getStoreIdByCode(string $storeId, array $stores): ?int
    {
        foreach ($stores as $store) {
            if ($store->getCode() === $storeId) {
                return (int)$store->getId();
            }
        }

        return null;
    }
}
