<?php
declare(strict_types=1);

namespace Laenen\SinglePageVariants\Core\Content\Seo;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandler;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Profiling\Profiler;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

readonly class ProductMainProductSeoUrlPlaceholderHandler implements SeoUrlPlaceholderHandlerInterface
{
    public function __construct(
        private SeoUrlPlaceholderHandlerInterface $decorated,
        private Connection $connection,
    ) {
    }

    public function generate($name, array $parameters = []): string
    {
        return $this->decorated->generate($name, $parameters);
    }

    public function replace(string $content, string $host, SalesChannelContext $context): string
    {
        return Profiler::trace('product-main-seo-url-replacer', function () use ($content, $host, $context) {
            $matches = [];

            if (preg_match_all('/' . SeoUrlPlaceholderHandler::DOMAIN_PLACEHOLDER . '\/detail\/[^#]*#/', $content,
                $matches)
            ) {
                $mapping = $this->getProductMapping($matches[0]);

                if (count($mapping) > 0) {
                    $content = str_replace(array_keys($mapping), array_values($mapping), $content);
                }

                return $this->decorated->replace($content, $host, $context);
            }

            return $content;
        });
    }

    /**
     * @param  list<string>  $matches
     *
     * @return array<string, string>
     */
    private function getProductMapping(array $matches): array
    {
        $ids = [];
        $placeholder = \strlen(SeoUrlPlaceholderHandler::DOMAIN_PLACEHOLDER . '/detail/');
        foreach ($matches as $match) {
            // remove self::DOMAIN_PLACEHOLDER and /detail/ from start
            // remove # from end
            $ids[$match] = substr((string)$match, $placeholder, -1);
        }

        $ids = array_filter($ids, static fn(string $id): bool => Uuid::isValid($id));
        $ids = array_values(array_unique($ids));
        $ids = Uuid::fromHexToBytesList($ids);

        $mapped = $this->connection->fetchAllKeyValue(
            <<<SQL
SELECT LOWER(HEX(id)), LOWER(HEX(parent_id)) 
FROM product 
WHERE id IN (:ids) 
AND parent_id IS NOT NULL;
SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::BINARY]
        );

        $placeholders = [];
        foreach ($mapped as $id => $parentId) {
            $placeholders[SeoUrlPlaceholderHandler::DOMAIN_PLACEHOLDER . '/detail/' . $id] = SeoUrlPlaceholderHandler::DOMAIN_PLACEHOLDER . '/detail/' .$parentId;
        }

        return $placeholders;
    }
}