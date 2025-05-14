<?php
declare(strict_types=1);

namespace Laenen\SinglePageVariants\Core\Content\Seo;

use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlMapping;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteConfig;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

readonly class VariantProductPageSeoUrlRoute implements SeoUrlRouteInterface
{
    public function __construct(
        private SeoUrlRouteInterface $decorated
    ) {
    }

    public function getConfig(): SeoUrlRouteConfig
    {
        return $this->decorated->getConfig();
    }

    public function prepareCriteria(Criteria $criteria, SalesChannelEntity $salesChannel): void
    {
        $this->decorated->prepareCriteria($criteria, $salesChannel);

        $criteria->addFilter(new EqualsFilter('parentId', null));
    }

    public function getMapping(Entity $entity, ?SalesChannelEntity $salesChannel): SeoUrlMapping
    {
        return $this->decorated->getMapping($entity, $salesChannel);
    }
}