<?php declare(strict_types=1);

namespace LumaricSearch\Services;

use Plenty\Modules\Item\Variation\Contracts\VariationSearchRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

/**
 * Exports active plentymarkets variations to the Lumaric Search index.
 *
 * Flow
 * ────
 * 1. Paginate through all active variations using the internal repository.
 * 2. Map each variation to a Lumaric document (external_id = variation number).
 * 3. Fetch the field schema from the Lumaric API.
 * 4. POST documents to the Lumaric /documents/sync endpoint in batches of 500.
 *
 * Config keys (plugin config):
 *   LumaricSearch.lumaricBaseUrl   – base URL, e.g. https://app.lumaric.com
 *   LumaricSearch.lumaricEngineId  – integer engine ID
 *   LumaricSearch.apiKey           – API key (with or without "API:" prefix)
 *   LumaricSearch.shopUrl          – public shop base URL for building product URLs
 *   LumaricSearch.exportEnabled    – bool; skip export when false
 */
class ExportService
{
    use Loggable;

    private const BATCH_SIZE = 500;

    /** @var ConfigRepository */
    private $config;

    public function __construct(
        ConfigRepository $config
    ) {
        $this->config = $config;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function exportProducts(): void
    {
        if (!$this->config->get('LumaricSearch.exportEnabled', false)) {
            return;
        }

        $apiUrl   = $this->getApiUrl();
        $engineId = $this->getEngineId();
        $apiKey   = $this->getApiKey();

        if (!$apiUrl || $engineId === null || !$apiKey) {
            $this->getLogger(__METHOD__)->error('Lumaric export aborted: missing configuration (lumaricBaseUrl, lumaricEngineId, apiKey)');

            return;
        }

        $headers = [
            'X-Engine'      => $engineId,
            'Authorization' => 'Bearer API:' . $apiKey,
        ];

        // Fetch Lumaric field schema once so we can map technical_name → field ID
        /** @var array{items: list<array{technical_name: string, id: string, data_type: string}>} */
        $fieldsResponse = $this->sendJsonRequest('GET', $apiUrl . '/fields', $headers, null, 10);
        $fieldsData = json_decode($fieldsResponse['body'], true);

        if (!is_array($fieldsData) || !isset($fieldsData['items']) || !is_array($fieldsData['items'])) {
            throw new \RuntimeException('Invalid Lumaric fields response');
        }

        $fieldMap = [];
        foreach ($fieldsData['items'] as $field) {
            $fieldMap[$field['technical_name']] = $field;
        }

        $shopUrl = rtrim((string) $this->config->get('LumaricSearch.shopUrl', ''), '/');

        $page      = 1;
        $documents = [];

        do {
            [$variations, $isLastPage] = $this->fetchVariations($page);

            foreach ($variations as $variation) {
                $document = $this->mapVariation($variation, $shopUrl);
                if ($document === null) continue;

                $uploadDoc = [
                    'external_id' => $document['sku'] ?? null,
                    'name'        => $document['name'] ?? null,
                    'fields'      => [],
                ];

                foreach ($document as $key => $value) {
                    if (!isset($fieldMap[$key])) continue;

                    $field = $fieldMap[$key];
                    $value = $this->convertToType((string) $value, $field['data_type']);
                    if ($value === null) continue;

                    $uploadDoc['fields'][] = [
                        'field_id' => $field['id'],
                        'value'    => $value,
                    ];
                }

                $documents[] = $uploadDoc;

                if (count($documents) >= self::BATCH_SIZE) {
                    $this->syncBatch($apiUrl, $headers, $documents);
                    $documents = [];
                }
            }

            ++$page;
        } while (!$isLastPage);

        // Upload any remaining documents
        if ($documents !== []) {
            $this->syncBatch($apiUrl, $headers, $documents);
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Returns [variations[], isLastPage].
     *
     * Uses the plentymarkets VariationSearchRepositoryContract.
     * The 'with' values follow the plentymarkets plugin API conventions;
     * adjust the 'with' list if your plentymarkets version uses different names.
     *
     * @return array{list<mixed>, bool}
     */
    private function fetchVariations(int $page): array
    {
        /** @var VariationSearchRepositoryContract $repo */
        $repo = pluginApp(VariationSearchRepositoryContract::class);

        $repo->setFilters(['isActive' => 1]);
        $repo->setSearchParams([
            'page'         => $page,
            'itemsPerPage' => 250,
            'with'         => [
                'variationSalesPrices',
                'variationImages',
                'variationTexts',
                'itemImages',
                'item',
                'item.itemTexts',
            ],
        ]);

        $result = $repo->search();

        /** @var list<mixed> $entries */
        $entries    = $result->getResult() ?? [];
        $isLastPage = ($result->isLastPage() ?? true);

        return [$entries, $isLastPage];
    }

    private function getKey($obj, string $key, $default = null) {
        if (is_array($obj))  return $obj[$key]  ?? $default;

        return $default;
    }

    /**
     * Maps a single variation object/array to a flat key-value document.
     *
     * The field names match the Shopware import/export profile mapping:
     *   id, sku, name, description, image_url, keywords, available, price, list_price, url
     *
     * Adjust property access below to match the actual shape of the variation
     * models returned by your plentymarkets instance.
     *
     * @param mixed  $variation
     * @return array<string, mixed>|null
     */
    private function mapVariation($variation, string $shopUrl): ?array
    {
        $variationId = $this->getKey($variation, 'id');
        $number      = $this->getKey($variation, 'number');  // variation number = SKU

        if (!$variationId || !$number) return null;

        // ── Texts ──────────────────────────────────────────────────────────────
        // variationTexts / item.itemTexts – first available language entry
        $texts = $this->getKey($variation, 'variationTexts') ?? $this->getKey($this->getKey($variation, 'item'), 'itemTexts') ?? [];
        if (!is_array($texts) || $texts === []) {
            $texts = [];
        }
        $firstText   = $texts[0]  ?? [];
        $name        = $this->getKey($firstText, 'name1')      ?? $this->getKey($firstText, 'name') ?? '';
        $description = $this->getKey($firstText, 'description') ?? '';
        $keywords    = $this->getKey($firstText, 'keywords')    ?? '';

        // ── Image URL ──────────────────────────────────────────────────────────
        $images   = $this->getKey($variation, 'variationImages') ?? $this->getKey($variation, 'itemImages') ?? [];
        $imageUrl = '';
        if (is_array($images) && $images !== []) {
            // Prefer the first variation-specific image, fall back to item image
            $firstImage = $images[0] ?? [];
            $imageUrl   = $this->getKey($firstImage, 'urlMiddle')
                ?? $this->getKey($firstImage, 'url')
                ?? $this->getKey($this->getKey($firstImage, 'image'), 'urlMiddle')
                ?? '';
        }

        // ── Prices ─────────────────────────────────────────────────────────────
        $prices     = $this->getKey($variation, 'variationSalesPrices') ?? [];
        $price      = 0.0;
        $listPrice  = 0.0;
        if (is_array($prices)) {
            foreach ($prices as $priceEntry) {
                // salesPriceId = 1 is typically the standard sales price in plentymarkets
                if ($this->getKey($priceEntry, 'salesPriceId') == 1) {
                    $price = (float) ($this->getKey($priceEntry, 'price') ?? 0.0);
                }
                // salesPriceId = 2 is typically the recommended retail price (RRP/list price)
                if ($this->getKey($priceEntry, 'salesPriceId') == 2) {
                    $listPrice = (float) ($this->getKey($priceEntry, 'price') ?? 0.0);
                }
            }
        }

        // ── Availability ───────────────────────────────────────────────────────
        $isActive    = (bool) $this->getKey($variation, 'isActive', false);
        $stockNet    = (float) ($this->getKey($variation, 'stockNet') ?? 0.0);
        $isAvailable = $isActive && $stockNet > 0;

        // ── URL ────────────────────────────────────────────────────────────────
        // Build URL from shop base and variation URL key / SEO slug if available.
        // Adjust the property name if your plentymarkets returns a different field.
        $urlPath = $this->getKey($variation, 'urlPath') ?? $this->getKey($this->getKey($variation, 'item'), 'defaultCategories[0].urlPath') ?? null;
        $url     = $urlPath ? $shopUrl . '/' . ltrim((string) $urlPath, '/') : '';

        return [
            'id'          => (string) $variationId,
            'sku'         => (string) $number,
            'name'        => (string) $name,
            'description' => (string) $description,
            'image_url'   => (string) $imageUrl,
            'keywords'    => (string) $keywords,
            'available'   => $isAvailable ? '1' : '0',
            'price'       => (string) $price,
            'list_price'  => (string) $listPrice,
            'url'         => $url,
        ];
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param array<string, mixed>       $headers
     */
    private function syncBatch(string $apiUrl, array $headers, array $documents): void
    {
        $response = $this->sendJsonRequest('POST', $apiUrl . '/documents/sync', $headers, $documents, 180);

        if ($response['statusCode'] !== 204) {
            throw new \RuntimeException(
                'Failed to upload documents to Lumaric Search: ' . $response['body']
            );
        }
    }

    private function convertToType(string $value, string $type)
    {
        if ($value === '') return null;

        switch ($type) {
            case 'string':
                return $value;
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'bool':
                return in_array(strtolower($value), ['1', 'true', 'yes'], true);
            /*case 'date':
            case 'datetime':
                return (new \DateTime($value))->format(\DateTime::ATOM);*/
            case 'string-array':
                return explode(',', $value);
            default:
                return $value;
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

    private function getApiKey(): ?string
    {
        $key = (string) $this->config->get('LumaricSearch.apiKey', '');
        $key = ltrim($key, 'API:');

        return $key ?: null;
    }

    /**
     * @param array<string, string|int> $headers
     * @param array<string, mixed>|list<array<string, mixed>>|null $jsonBody
     * @return array{statusCode: int, body: string}
     */
    private function sendJsonRequest(string $method, string $url, array $headers, $jsonBody = null, int $timeout = 10): array
    {
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        if ($jsonBody !== null) {
            $curlHeaders[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

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
        ];
    }
}
