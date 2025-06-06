<?php
declare(strict_types=1);

namespace Laenen\SinglePageVariants\Core\Content\Sitemap;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Service\ConfigHandler;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\RouterInterface;

/**
 * The implementation of the ProductUrlProvider in Shopware Core does not allow for proper decoration of the
 * 'get product ids' function, which means that it is necesary to copy and override the whole file instead.
 *
 * Following changes have been made:
 * - Variants do not appear in sitemap
 * - Variant parents do appear in sitemap
 *
 * Only the query of 'getProducts' has been changed, and has been noted using comments. Hopefully this makes it easy and
 * clear enough to update the code after future Shopware updates.
 */

class ProductUrlProvider extends AbstractUrlProvider
{
    final public const CHANGE_FREQ = 'hourly';

    private const CONFIG_EXCLUDE_LINKED_PRODUCTS = 'core.sitemap.excludeLinkedProducts';

    private const CONFIG_HIDE_AFTER_CLOSEOUT = 'core.listing.hideCloseoutProductsWhenOutOfStock';

    /**
     * @internal
     */
    public function __construct(
        private readonly ConfigHandler $configHandler,
        private readonly Connection $connection,
        private readonly ProductDefinition $definition,
        private readonly IteratorFactory $iteratorFactory,
        private readonly RouterInterface $router,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getDecorated(): AbstractUrlProvider
    {
        throw new DecorationPatternException(self::class);
    }

    public function getName(): string
    {
        return 'product';
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    public function getUrls(SalesChannelContext $context, int $limit, ?int $offset = null): UrlResult
    {
        $products = $this->getProducts($context, $limit, $offset);

        if (empty($products)) {
            return new UrlResult([], null);
        }

        $keys = FetchModeHelper::keyPair($products);

        $seoUrls = $this->getSeoUrls(array_values($keys), 'frontend.detail.page', $context, $this->connection);

        /** @var array<string, array{seo_path_info: string}> $seoUrls */
        $seoUrls = FetchModeHelper::groupUnique($seoUrls);

        $urls = [];
        $url = new Url();

        foreach ($products as $product) {
            $lastMod = $product['updated_at'] ?: $product['created_at'];

            $lastMod = (new \DateTime($lastMod))->format(Defaults::STORAGE_DATE_TIME_FORMAT);

            $newUrl = clone $url;

            if (isset($seoUrls[$product['id']])) {
                $newUrl->setLoc($seoUrls[$product['id']]['seo_path_info']);
            } else {
                $newUrl->setLoc($this->router->generate('frontend.detail.page', ['productId' => $product['id']]));
            }

            $newUrl->setLastmod(new \DateTime($lastMod));
            $newUrl->setChangefreq(self::CHANGE_FREQ);
            $newUrl->setResource(ProductEntity::class);
            $newUrl->setIdentifier($product['id']);

            $urls[] = $newUrl;
        }

        $keys = array_keys($keys);
        /** @var int|null $nextOffset */
        $nextOffset = array_pop($keys);

        return new UrlResult($urls, $nextOffset);
    }

    /**
     * @return list<array{id: string, created_at: string, updated_at: string}>
     */
    private function getProducts(SalesChannelContext $context, int $limit, ?int $offset): array
    {
        $lastId = null;
        if ($offset) {
            $lastId = ['offset' => $offset];
        }

        $iterator = $this->iteratorFactory->createIterator($this->definition, $lastId);
        $query = $iterator->getQuery();
        $query->setMaxResults($limit);

        $showAfterCloseout = !$this->systemConfigService->get(self::CONFIG_HIDE_AFTER_CLOSEOUT, $context->getSalesChannelId());

        $query->addSelect([
            '`product`.created_at as created_at',
            '`product`.updated_at as updated_at',
        ]);

        $query->leftJoin('`product`', '`product`', 'parent', '`product`.parent_id = parent.id');
        $query->innerJoin('`product`', 'product_visibility', 'visibilities', 'product.visibilities = visibilities.product_id');

        $query->andWhere('`product`.version_id = :versionId');

        if ($showAfterCloseout) {
            $query->andWhere('(`product`.available = 1 OR `product`.is_closeout)');
        } else {
            $query->andWhere('`product`.available = 1');
        }

        $query->andWhere('IFNULL(`product`.active, parent.active) = 1');

        // This is the original WHERE part, which only returns "single" products, and variants themselves, but no parents
        // $query->andWhere('(`product`.child_count = 0 OR `product`.parent_id IS NOT NULL)');

        // This is our replacement, which returns "single" products, or parents, but not variants themselves
        $query->andWhere('`product`.parent_id IS NULL');

        // This following WHERE part from original core has been commented out, since it will always return true after the previous changed WHERE part
        //$query->andWhere('(`product`.parent_id IS NULL OR parent.canonical_product_id IS NULL OR parent.canonical_product_id = `product`.id)');

        $query->andWhere('visibilities.product_version_id = :versionId');
        $query->andWhere('visibilities.sales_channel_id = :salesChannelId');

        $excludedProductIds = $this->getExcludedProductIds($context);
        if (!empty($excludedProductIds)) {
            $query->andWhere('`product`.id NOT IN (:productIds)');
            $query->setParameter('productIds', Uuid::fromHexToBytesList($excludedProductIds), ArrayParameterType::BINARY);
        }

        $excludeLinkedProducts = $this->systemConfigService->getBool(self::CONFIG_EXCLUDE_LINKED_PRODUCTS, $context->getSalesChannelId());
        if ($excludeLinkedProducts) {
            $query->andWhere('visibilities.visibility != :excludedVisibility');
            $query->setParameter('excludedVisibility', ProductVisibilityDefinition::VISIBILITY_LINK);
        }

        $query->setParameter('versionId', Uuid::fromHexToBytes(Defaults::LIVE_VERSION));
        $query->setParameter('salesChannelId', Uuid::fromHexToBytes($context->getSalesChannelId()));

        /** @var list<array{id: string, created_at: string, updated_at: string}> $result */
        $result = $query->executeQuery()->fetchAllAssociative();

        return $result;
    }

    /**
     * @return array<string>
     */
    private function getExcludedProductIds(SalesChannelContext $salesChannelContext): array
    {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $excludedUrls = $this->configHandler->get(ConfigHandler::EXCLUDED_URLS_KEY);
        if (empty($excludedUrls)) {
            return [];
        }

        $excludedUrls = array_filter($excludedUrls, static function (array $excludedUrl) use ($salesChannelId) {
            if ($excludedUrl['resource'] !== ProductEntity::class) {
                return false;
            }

            if ($excludedUrl['salesChannelId'] !== $salesChannelId) {
                return false;
            }

            return true;
        });

        return array_column($excludedUrls, 'identifier');
    }
}
