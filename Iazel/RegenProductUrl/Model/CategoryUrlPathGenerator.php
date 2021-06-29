<?php

namespace Iazel\RegenProductUrl\Model;

use Magento\Catalog\Model\Category;

class CategoryUrlPathGenerator extends \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator
{
    /**
     * @param Category $category
     *
     * @return bool
     */
    protected function isNeedToGenerateUrlPathForParent($category): bool
    {
        /* Force true when command is run from the CLI */
        if (PHP_SAPI == 'cli') {
            return true;
        }

        return $category->isObjectNew() ||
            $category->getLevel() >= self::MINIMAL_CATEGORY_LEVEL_FOR_PROCESSING;
    }
}
