<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[RouteScope(scopes: ['storefront'])]
class ExampleController extends StorefrontController
{
    #[Route(
        path: '/addresshashessw6/example', 
        name: 'frontend.addresshashessw6.example', 
        methods: ['GET']
    )]
    public function exampleAction(): Response
    {
        return $this->renderStorefront('@TopdataAddressHashesSW6/storefront/example.html.twig', [
            'pluginName' => 'AddressHashesSW6'
        ]);
    }
}