<?php
declare(strict_types=1);

namespace Laenen\SinglePageVariants\Storefront\Controller;

use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\Exception\VariantNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\FindVariant\AbstractFindProductVariantRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class SwitchController extends StorefrontController
{
    public function __construct(
        private readonly AbstractFindProductVariantRoute $findVariantRoute,
    ) {
    }

    // Overrides frontend.detail.switch route from core
    #[Route(path: '/detail/{productId}/switch', name: 'frontend.detail.switch', defaults: [
        'XmlHttpRequest' => true,
        '_httpCache' => true,
    ], methods: ['GET'])]
    public function switch(string $productId, Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $switchedGroup = $request->query->has('switched') ? (string)$request->query->get('switched') : null;

        try {
            $options = json_decode($request->query->get('options', '[]'), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $options = [];
        }

        $variantRequestData = [
            'switchedGroup' => $switchedGroup,
            'options' => $options,
        ];

        $variantRequest = $request->duplicate($variantRequestData);

        try {
            $variantResponse = $this->findVariantRoute->load(
                $productId,
                $variantRequest,
                $salesChannelContext
            );

            $productId = $variantResponse->getFoundCombination()->getVariantId();
        } catch (VariantNotFoundException|ProductNotFoundException) {
            // nth
        }

        $request->headers->remove('X-Requested-With');
        return $this->forwardToRoute('frontend.detail.page', [], ['productId' => $productId]);
    }
}
