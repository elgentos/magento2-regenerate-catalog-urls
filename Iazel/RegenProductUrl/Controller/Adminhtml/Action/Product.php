<?php

declare(strict_types=1);

namespace Iazel\RegenProductUrl\Controller\Adminhtml\Action;

use Exception;
use Iazel\RegenProductUrl\Service\RegenerateProductUrl;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

class Product extends Action
{
    /**
     * @var RegenerateProductUrl
     */
    private $regenerateProductUrl;

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * Constructor.
     *
     * @param CollectionFactory    $collectionFactory
     * @param Filter               $filter
     * @param RegenerateProductUrl $regenerateProductUrl
     * @param Context              $context
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        Filter $filter,
        RegenerateProductUrl $regenerateProductUrl,
        Context $context
    ) {
        $this->collectionFactory    = $collectionFactory;
        $this->filter               = $filter;
        $this->regenerateProductUrl = $regenerateProductUrl;

        parent::__construct($context);
    }

    /**
     * Execute action based on request and return result
     *
     * Note: Request will be added as operation argument in future
     *
     * @return Redirect
     * @throws LocalizedException
     */
    public function execute(): Redirect
    {
        $productIds = $this->getSelectedProductIds();
        $storeId    = (int) $this->getRequest()->getParam('store', 0);
        $filters    = $this->getRequest()->getParam('filters', []);

        if (isset($filters['store_id'])) {
            $storeId = (int) $filters['store_id'];
        }

        try {
            $this->regenerateProductUrl->execute($productIds, $storeId);
            $this->messageManager->addSuccessMessage(
                __(
                    'Successfully regenerated %1 urls for store id %2.',
                    $this->regenerateProductUrl->getRegeneratedCount(),
                    $storeId
                )
            );
        } catch (Exception $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while regenerating the product(s) url.')
            );
        }

        $resultRedirect = $this->resultRedirectFactory->create();

        return $resultRedirect->setPath('catalog/product/index');
    }

    /**
     * @return array
     * @throws LocalizedException
     */
    private function getSelectedProductIds(): array
    {
        return $this->filter->getCollection(
            $this->collectionFactory->create()
        )->getAllIds();
    }
}
