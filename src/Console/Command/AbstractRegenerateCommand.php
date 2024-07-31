<?php

declare(strict_types=1);

namespace Elgentos\RegenerateCatalogUrls\Console\Command;

use Elgentos\RegenerateCatalogUrls\Service\RegenerateProductUrl;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

abstract class AbstractRegenerateCommand extends Command
{
    /**
     * @var InputInterface
     */
    protected InputInterface $input;

    /**
     * @var OutputInterface
     */
    protected OutputInterface $output;

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;

    /**
     * @var State
     */
    protected State $state;

    /**
     * @var RegenerateProductUrl
     */
    protected RegenerateProductUrl $regenerateProductUrl;

    /**
     * @var QuestionHelper
     */
    protected QuestionHelper $questionHelper;

    /**
     * Constructor
     *
     * @param StoreManagerInterface $storeManager
     * @param State $state
     * @param RegenerateProductUrl $regenerateProductUrl
     * @param QuestionHelper $questionHelper
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        State $state,
        RegenerateProductUrl $regenerateProductUrl,
        QuestionHelper $questionHelper
    ) {
        $this->storeManager = $storeManager;
        $this->state = $state;
        $this->regenerateProductUrl = $regenerateProductUrl;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
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

    /**
     * Get chosen stores
     *
     * @return array
     * @throws LocalizedException
     */
    protected function getChosenStores(): array
    {
        $storeInput = $this->input->getOption('store');

        if ($this->storeManager->isSingleStoreMode()) {
            return [1];
        }

        $storeId = false;
        if (is_numeric($storeInput)) {
            $storeId = (int)$storeInput;
        } elseif ($storeInput === 'all') {
            $storeId = $storeInput;
        } elseif (is_string($storeInput)) {
            $storeId = $this->getStoreIdByCode($storeInput);
        } elseif (false === $storeInput) {
            $choices = array_merge(['all'], array_map(fn ($store) => $store->getCode(), $this->getAllStores()));
            $question = new ChoiceQuestion(__('Pick a store')->getText(), $choices, 'all');
            $storeCode = $this->questionHelper->ask($this->input, $this->output, $question);
            $storeId = ($storeCode === 'all' ? 'all' : $this->getStoreIdByCode($storeCode));
        }

        if ($storeId === 'all') {
            $stores = array_map(fn ($store) => $store->getId(), $this->getAllStores());
        } else {
            $stores = [$storeId];
        }

        return $stores;
    }

    /**
     * Get all stores
     *
     * @param bool|null $withDefault
     * @return array
     */
    protected function getAllStores(?bool $withDefault = false): array
    {
        return $this->storeManager->getStores($withDefault);
    }

    /**
     * Get Store ID by code
     *
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

        throw new LocalizedException(__(
            'The store that was requested (%1) wasn\'t found. Verify the store and try again.',
            $storeCode
        ));
    }
}
