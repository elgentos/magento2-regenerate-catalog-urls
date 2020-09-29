<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Iazel\RegenProductUrl\Service\RegenerateProductUrl;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegenerateProductUrlCommand extends Command
{
    /**
     * @var \Magento\Framework\App\State
     */
    private $state;
    /**
     * @var StoreManagerInterface\Proxy
     */
    private $storeManager;
    /**
     * @var RegenerateProductUrl
     */
    private $regenerateProductUrl;

    /**
     * RegenerateProductUrlCommand constructor.
     * @param State $state
     * @param StoreManagerInterface\Proxy $storeManager
     * @param RegenerateProductUrl $regenerateProductUrl
     */
    public function __construct(
        State $state,
        StoreManagerInterface\Proxy $storeManager,
        RegenerateProductUrl $regenerateProductUrl
    ) {
        $this->state = $state;
        $this->storeManager = $storeManager;
        $this->regenerateProductUrl = $regenerateProductUrl;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('regenerate:product:url')
            ->setDescription('Regenerate url for given products')
            ->addOption(
                'store',
                's',
                InputOption::VALUE_REQUIRED,
                'Regenerate for one specific store view',
                Store::DEFAULT_STORE_ID
            )
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'Product IDs to regenerate',
                []
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

        try {
            $this->regenerateProductUrl->setOutput($output);
            $this->regenerateProductUrl->execute($input->getArgument('pids'), (int) $storeId);
        } catch (NoSuchEntityException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
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
