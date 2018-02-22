<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateCategoryUrlCommand extends Command
{
    /**
     * @var CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;

    public function __construct(
        State $state,
        Collection $collection,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $this->state = $state;
        $this->collection = $collection;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('iazel:regenurl:category')
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
        //$this->collection->addStoreFilter($store_id)->setStoreId($store_id);

        $cids = $inp->getArgument('cids');
        if( !empty($cids) )
            $this->collection->addIdFilter($cids);

        $this->collection->addAttributeToSelect(['name', 'url_path', 'url_key']);
        $list = $this->collection->load();
        $regenerated = 0;
        foreach($list as $category)
        {
            $out->writeln('Regenerating urls for ' . $category->getName() . ' (' . $category->getId() . ')');
            if($store_id !== Store::DEFAULT_STORE_ID) {
                $category->setStoreId($store_id);
            }

            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID => $category->getId(),
                UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
                UrlRewrite::REDIRECT_TYPE => 0,
                UrlRewrite::STORE_ID => $store_id
            ]);

            $newUrls = $this->categoryUrlRewriteGenerator->generate($category);
            try {
                $this->urlPersist->replace($newUrls);
                $regenerated += count($newUrls);
            }
            catch(\Exception $e) {
                $out->writeln(sprintf('<error>Duplicated url for store ID %d, category %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store_id, $category->getId(), $category->getName(), $e->getMessage(), implode(PHP_EOL, array_keys($newUrls))));
            }
        }
        $out->writeln('Done regenerating. Regenerated ' . $regenerated . ' urls');
    }
}
