<?php declare(strict_types=1);

namespace LumaricSearch\Providers;

use LumaricSearch\Controllers\SearchController;
use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class LumaricSearchRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        // Full-text search — frontend POSTs { query, page, pageSize } and receives
        // a JSON object of { items, token, total, page, pageSize }.
        $router->post('lumaric/search', SearchController::class . '@search');

        // Field search — like above but searches a single named field (e.g. "sku").
        $router->post('lumaric/search/field', SearchController::class . '@searchField');
    }
}
