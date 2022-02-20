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
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

abstract class AbstractRegenerateCommand extends Command
{
    protected StoreManagerInterface $storeManager;

    protected State $state;

    protected RegenerateProductUrl $regenerateProductUrl;

    protected QuestionHelper $questionHelper;

    public function __construct(
        StoreManagerInterface $storeManager,
        State                 $state,
        RegenerateProductUrl  $regenerateProductUrl,
        QuestionHelper        $questionHelper
    ) {
        $this->storeManager = $storeManager;
        $this->state = $state;
        $this->regenerateProductUrl = $regenerateProductUrl;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addOption(
            'store',
            's',
            InputOption::VALUE_OPTIONAL,
            'Regenerate for a specific store view',
            false
        );
    }

    protected function getStoreId()
    {
        $storeInput = $this->input->getOption('store');

        if ($storeInput === 'all') {
            return $storeInput;
        }

        $storeId = false;
        if (is_numeric($storeInput)) {
            $storeId = (int) $storeInput;
        } elseif (is_string($storeInput)) {
            $storeId = $this->getStoreIdByCode($storeInput);
        } elseif (false === $storeInput) {
            $choices = array_merge(['all'], array_map(fn ($store) => $store->getCode(), $this->getAllStores()));
            $question = new ChoiceQuestion(__('Pick a store')->getText(), $choices, 'all');
            $storeCode = $this->questionHelper->ask($this->input, $this->output, $question);
            $storeId = $this->getStoreIdByCode($storeCode);
        }

        return $storeId;
    }

    protected function getAllStores($withDefault = false): array
    {
        return $this->storeManager->getStores($withDefault);
    }

    /**
     * @param string $storeCode
     * @return null|int
     * @throws LocalizedException
     */
    protected function getStoreIdByCode(string $storeCode): ?int
    {
        foreach ($this->getAllStores() as $store) {
            if ($store->getCode() === $storeCode) {
                return (int)$store->getId();
            }
        }

        throw new LocalizedException(__('The store that was requested (%1) wasn\'t found. Verify the store and try again.', $storeCode));
    }
}
