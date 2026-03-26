<?php declare(strict_types=1);

namespace LumaricSearch\Controllers;

use LumaricSearch\Services\SearchService;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

/**
 * HTTP controller backing the two Lumaric frontend routes:
 *
 *   POST /lumaric/search        – full-text search
 *   POST /lumaric/search/field  – single-field search (e.g. by SKU)
 *
 * Both endpoints accept JSON:
 *   { "query": "...", "page": 1, "pageSize": 24 }
 *
 * Both return JSON:
 *   { "items": [{document_id, product_number, position}],
 *     "token": "...", "total": 0, "page": 1, "pageSize": 24 }
 *
 * The frontend JavaScript uses the returned items to:
 *   1. Sort the native plentymarkets search results in Lumaric order.
 *   2. Attach click-tracking parameters to every product link.
 */
class SearchController
{
    /** @var SearchService */
    private $searchService;

    /** @var ConfigRepository */
    private $config;

    /** @var Response */
    private $response;

    public function __construct(
        SearchService $searchService,
        ConfigRepository $config,
        Response $response
    ) {
        $this->searchService = $searchService;
        $this->config = $config;
        $this->response = $response;
    }

    public function test(): Response
    {
        return $this->json(['success1' => true]);
    }

    public function search(Request $request): Response
    {
        if (!$this->config->get('LumaricSearch.searchEnabled', false)) {
            return $this->json(null);
        }

        $query    = (string)  ($request->input('query', ''));
        $page     = (int)     ($request->input('page', 1));
        $pageSize = (int)     ($request->input('pageSize', 24));

        $result = $this->searchService->search(
            $request,
            $query,
            $page > 0 ? $page : 1,
            $pageSize > 0 ? $pageSize : 24
        );

        return $this->json($result);
    }

    public function searchField(Request $request): Response
    {
        if (!$this->config->get('LumaricSearch.suggestEnabled', false)) {
            return $this->json(null);
        }

        $query     = (string) ($request->input('query', ''));
        $fieldName = (string) ($this->config->get('LumaricSearch.skuFieldName', 'sku'));
        $page      = (int)    ($request->input('page', 1));
        $pageSize  = (int)    ($request->input('pageSize', 24));

        $result = $this->searchService->searchField(
            $request,
            $query,
            $fieldName,
            $page > 0 ? $page : 1,
            $pageSize > 0 ? $pageSize : 24
        );

        return $this->json($result);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function json($data): Response
    {
        $this->response->headers->set('Content-Type', 'application/json');

        return $this->response->make(
            json_encode($data ?? ['items' => [], 'token' => '', 'total' => 0, 'page' => 1, 'pageSize' => 0]),
            $data !== null ? 200 : 503
        );
    }
}
