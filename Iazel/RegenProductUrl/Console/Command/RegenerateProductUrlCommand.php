<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;

class RegenerateProductUrlCommand extends Command
{
    /**
     * @var ProductUrlRewriteGenerator
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepo;

    public function __construct(
        \Magento\Framework\App\State $state,
        ProductRepositoryInterface $productRepo,
        ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $state->setAreaCode('adminhtml');
        $this->productRepo = $productRepo;
        $this->productUrlRewriteGenerator = $productUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('iazel:regenurl')
            ->setDescription('Regenerate url for given products')
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'Products to regenerate'
            )
            ->addOption(
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                0
            )
            ;
        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {
        $pids = $inp->getArgument('pids');
        $store_id = $inp->getOption('store');

        foreach($pids as $pid) {
            $product = $this->productRepo->getById($pid, false, $store_id);

            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID => $product->getId(),
                UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                UrlRewrite::REDIRECT_TYPE => 0,
                UrlRewrite::STORE_ID => $store_id
            ]);
            try {
                $this->urlPersist->replace(
                    $this->productUrlRewriteGenerator->generate($product)
                );
            }
            catch(\Exception $e) {
                $out->writeln("<error>$pid</error>");
            }
        }
    }
}
