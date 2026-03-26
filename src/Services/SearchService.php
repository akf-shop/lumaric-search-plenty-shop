<?php declare(strict_types=1);

namespace LumaricSearch\Services;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Log\Loggable;

class SearchService
{
    use Loggable;

    public const SESSION_COOKIE_NAME     = 'lumaric-search-session';
    public const CLICK_TOKEN_PARAMETER   = 'lumaricClickToken';
    public const CLICK_TOKENS_PARAMETER  = 'lumaricClickTokens';

    /** @var ConfigRepository */
    private $config;

    public function __construct(
        ConfigRepository $config
    ) {
        $this->config = $config;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Full-text search via the Lumaric API.
     * Returns ordered product numbers (SKUs) together with click-tracking data.
     */
    public function search(
        Request $request,
        string  $search,
        ?int    $page     = null,
        ?int    $pageSize = null
    ): ?array {
        $apiUrl   = $this->getApiUrl();
        $engineId = $this->getEngineId();
        $fieldName = $this->config->get('LumaricSearch.skuFieldName', 'sku');

        if (!$apiUrl || $engineId === null || !$fieldName) {
            return ['state' => 'error', 'message' => 'Invalid configuration', 'apiUrl' => $apiUrl, 'engineId' => $engineId, 'fieldName' => $fieldName];
        }

        if (trim($search) === '') {
            return [
                'search' => $search,
                'items' => [],
                'token' => '',
                'total' => 0,
                'page' => $page !== null ? $page : 1,
                'pageSize' => $pageSize !== null ? $pageSize : 0,
            ];
        }

        try {
            $headers = $this->buildRequestHeaders($request, $engineId);

            $body  = ['query' => $search, 'sort' => null, 'filter' => null];
            $query = [];
            if ($page !== null)     $query['page']     = $page;
            if ($pageSize !== null) $query['pageSize'] = $pageSize;

            $response = $this->sendJsonRequest('POST', $apiUrl . '/search', $headers, $body, $query, 10);

            $this->handleSessionCookie($response['headers']);

            /** @var array{fields: array{id: int, name: string}[], items: array{document: array{id: int, fields: array{fieldId: int, value: mixed}[]}}[], token: string, page: int, pageSize: int, total: int} */
            $data   = json_decode($response['body'], true);
            $fields = $data['fields'] ?? [];

            // Locate the field ID that holds the SKU value
            $skuFieldId = null;
            foreach ($fields as $field) {
                if ($field['name'] === $fieldName) {
                    $skuFieldId = $field['id'];
                    break;
                }
            }

            $items    = $data['items'] ?? [];
            $position = ($page !== null && $pageSize !== null) ? ($page - 1) * $pageSize : 0;

            $searchItems = [];
            foreach ($items as $searchDocument) {
                if (!isset($searchDocument['document'])) continue;

                $document      = $searchDocument['document'];
                $documentFields = $document['fields'] ?? [];

                $productNumber = null;
                foreach ($documentFields as $df) {
                    if ($df['fieldId'] === $skuFieldId) {
                        $productNumber = $df['value'] ?? null;
                        break;
                    }
                }
                if (!$productNumber || !is_string($productNumber)) continue;

                $searchItems[] = [
                    'document_id'    => $document['id'],
                    'product_number' => $productNumber,
                    'position'       => $position,
                ];
                ++$position;
            }

            return [
                'search' => $search,
                'items' => $searchItems,
                'token' => isset($data['token']) ? $data['token'] : '',
                'total' => isset($data['total']) ? $data['total'] : count($items),
                'page' => isset($data['page']) ? $data['page'] : ($page !== null ? $page : 1),
                'pageSize' => isset($data['pageSize']) ? $data['pageSize'] : ($pageSize !== null ? $pageSize : count($items)),
            ];
        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('Lumaric search failed', [
                'exception' => $e->getMessage(),
                'search'    => $search,
            ]);

            return ['state' => 'error', 'exception' => $e->getMessage()];
        }
    }

    /**
     * Single-field search (e.g. by SKU or name prefix).
     */
    public function searchField(
        Request $request,
        string  $search,
        string  $fieldName,
        ?int    $page     = null,
        ?int    $pageSize = null
    ): ?array {
        $apiUrl   = $this->getApiUrl();
        $engineId = $this->getEngineId();

        if (!$apiUrl || $engineId === null || !$fieldName) {
            return null;
        }

        if (trim($search) === '') {
            return [
                'search' => $search,
                'items' => [],
                'token' => '',
                'total' => 0,
                'page' => $page !== null ? $page : 1,
                'pageSize' => $pageSize !== null ? $pageSize : 0,
            ];
        }

        try {
            $headers = $this->buildRequestHeaders($request, $engineId);

            $body = [
                'query'      => $search,
                'sort'       => null,
                'filter'     => null,
                'field_name' => $fieldName,
            ];

            $response = $this->sendJsonRequest('POST', $apiUrl . '/search/field', $headers, $body, [], 10);

            $this->handleSessionCookie($response['headers']);

            /** @var array{items: list<array{document_id: int, value: string}>, token: string} */
            $data     = json_decode($response['body'], true);
            $items    = $data['items'] ?? [];
            $position = ($page !== null && $pageSize !== null) ? ($page - 1) * $pageSize : 0;

            $searchItems = [];
            foreach ($items as $item) {
                $searchItems[] = [
                    'document_id'    => $item['document_id'],
                    'product_number' => $item['value'],
                    'position'       => $position,
                ];
                ++$position;
            }

            return [
                'search' => $search,
                'items' => $searchItems,
                'token' => isset($data['token']) ? $data['token'] : '',
                'total' => count($items),
                'page' => $page !== null ? $page : 1,
                'pageSize' => $pageSize !== null ? $pageSize : count($items),
            ];
        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('Lumaric field search failed', [
                'exception' => $e->getMessage(),
                'search'    => $search,
            ]);

            return null;
        }
    }

    /**
     * Send conversion events to Lumaric for every click token found on the
     * order's line items.
     *
     * @param array<int, array{clickTokens?: list<string>}> $lineItemsData
     *        Keyed by basket item ID; value must contain 'clickTokens'.
     */
    public function trackConversions(array $lineItemsData, string $orderId = ''): void
    {
        $apiUrl   = $this->getApiUrl();
        $engineId = $this->getEngineId();

        if (!$apiUrl || $engineId === null) return;

        $headers = ['X-Engine' => $engineId];

        foreach ($lineItemsData as $itemId => $itemData) {
            $clickTokens = $itemData['clickTokens'] ?? [];
            if (!is_array($clickTokens)) continue;

            foreach ($clickTokens as $clickToken) {
                if (!$clickToken || !is_string($clickToken)) continue;

                try {
                    $response = $this->sendJsonRequest(
                        'POST',
                        rtrim($apiUrl, '/') . '/stats/conversion',
                        $headers,
                        ['click_token' => $clickToken],
                        [],
                        5
                    );

                    if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
                        $this->getLogger(__METHOD__)->warning('Lumaric conversion API returned non-200 status', [
                            'status'     => $response['statusCode'],
                            'orderId'    => $orderId,
                            'itemId'     => $itemId,
                            'clickToken' => $clickToken,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->getLogger(__METHOD__)->error('Lumaric conversion tracking failed', [
                        'exception'  => $e->getMessage(),
                        'orderId'    => $orderId,
                        'itemId'     => $itemId,
                        'clickToken' => $clickToken,
                    ]);
                }
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, int|string> */
    private function buildRequestHeaders(Request $request, int $engineId): array
    {
        $headers = ['X-Engine' => $engineId];

        $sessionId = isset($_COOKIE[self::SESSION_COOKIE_NAME]) ? $_COOKIE[self::SESSION_COOKIE_NAME] : null;
        $userAgent  = $request->header('User-Agent');

        if ($sessionId) $headers['X-Session']   = $sessionId;
        if ($userAgent)  $headers['User-Agent']  = $userAgent;

        return $headers;
    }

    private function handleSessionCookie(array $headers): void
    {
        $sessionHeaders = isset($headers['x-session']) ? $headers['x-session'] : [];
        if (isset($sessionHeaders[0])) {
            setcookie(self::SESSION_COOKIE_NAME, $sessionHeaders[0], time() + 3600, '/');
        }
    }

    private function getApiUrl(): ?string
    {
        $baseUrl = $this->config->get('LumaricSearch.lumaricBaseUrl', '');
        if (!$baseUrl) return null;

        return rtrim((string) $baseUrl, '/') . '/api';
    }

    private function getEngineId(): ?int
    {
        $engineId = $this->config->get('LumaricSearch.lumaricEngineId');

        return is_numeric($engineId) ? (int) $engineId : null;
    }

    /**
     * @param array<string, string|int> $headers
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, int|string> $query
     * @return array{statusCode: int, body: string, headers: array<string, list<string>>}
     */
    private function sendJsonRequest(string $method, string $url, array $headers, ?array $jsonBody = null, array $query = [], int $timeout = 10): array
    {
        if ($query !== []) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . http_build_query($query);
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        if ($jsonBody !== null) {
            $curlHeaders[] = 'Content-Type: application/json';
        }

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) use (&$responseHeaders) {
            $trimmed = trim($headerLine);
            if ($trimmed === '' || strpos($trimmed, ':') === false) {
                return strlen($headerLine);
            }

            list($name, $value) = explode(':', $trimmed, 2);
            $name = strtolower(trim($name));
            if (!isset($responseHeaders[$name])) {
                $responseHeaders[$name] = [];
            }
            $responseHeaders[$name][] = trim($value);

            return strlen($headerLine);
        });

        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'statusCode' => $statusCode,
            'body' => (string) $body,
            'headers' => $responseHeaders,
        ];
    }
}
