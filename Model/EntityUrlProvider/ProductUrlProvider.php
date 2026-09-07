<?php

declare(strict_types=1);

namespace Blackbird\CacheWarmer\Model\EntityUrlProvider;

use Blackbird\CacheWarmer\Api\Data\EntityQueueInterface;
use Blackbird\CacheWarmer\Api\EntityUrlProviderInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;
use Magento\Catalog\Model\ResourceModel\Product\Relation;

/**
 * URL provider for product entities
 */
class ProductUrlProvider implements EntityUrlProviderInterface
{
    /**
     * @param ProductHelper $productHelper
     * @param Emulation $storeEmulation
     * @param Relation $productRelation
     * @param Visibility $productVisibility
     * @param ProductCollectionFactory $productCollectionFactory
     */
    public function __construct(
        protected ProductHelper                                         $productHelper,
        protected Emulation                                             $storeEmulation,
        protected Relation $productRelation,
        protected Visibility                                            $productVisibility,
        protected ProductCollectionFactory                              $productCollectionFactory
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function getUrl(int $entityId, int $storeId): array
    {
        // Start store emulation
        $this->storeEmulation->startEnvironmentEmulation($storeId);

        try {
            $urls = [];

            // Get product URL using collection for better performance
            $productCollection = $this->productCollectionFactory->create()
                ->addAttributeToFilter('entity_id', $entityId)
                ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
                ->addAttributeToSelect(['visibility', 'url_key', 'url_path']);

            // Add URL rewrites to collection
            $productCollection->addUrlRewrite();

            $product = $productCollection->getFirstItem();


            foreach ($this->getUrlsFromProduct($product) as $url) {
                $urls[] = $url;
            }

            // Get parent product URLs if this is a child product
            $parentIds = $this->productRelation->getRelationsByChildren([$entityId]);
            if (!empty($parentIds[$entityId])) {
                // Load all parent products at once using collection
                $parentProductCollection = $this->productCollectionFactory->create()
                    ->addAttributeToFilter('entity_id', ['in' => $parentIds[$entityId]])
                    ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
                    ->addAttributeToSelect(['visibility', 'url_key', 'url_path']);

                // Add URL rewrites to collection
                $parentProductCollection->addUrlRewrite();

                // Process each parent product
                foreach ($parentProductCollection as $parentProduct) {
                    foreach ($this->getUrlsFromParentProduct($product, $parentProduct) as $url) {
                        $urls[] = $url;
                    }
                }
            }

            return array_unique($urls);
        } finally {
            // Stop store emulation
            $this->storeEmulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @param ProductInterface $product
     * @return array
     */
    public function getUrlsFromProduct(ProductInterface $product):array
    {
        // Check if product is visible
        if ($product->getId() && in_array($product->getVisibility(),  $this->productVisibility->getVisibleInSiteIds())) {
            return [$this->productHelper->getProductUrl($product)];
        }
        return [];
    }

    /**
     * @param ProductInterface $product
     * @param ProductInterface $parentProduct
     * @return array
     */
    public function getUrlsFromParentProduct(ProductInterface $product, ProductInterface $parentProduct):array
    {
        if (in_array($parentProduct->getVisibility(), $this->productVisibility->getVisibleInSiteIds())) {
            return [$this->productHelper->getProductUrl($parentProduct)];
        }
        return [];
    }

    /**
     * @inheritDoc
     */
    public function supports(string $entityType): bool
    {
        return $entityType === EntityQueueInterface::ENTITY_TYPE_PRODUCT;
    }
}
