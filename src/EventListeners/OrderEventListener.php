<?php declare(strict_types=1);

namespace LumaricSearch\EventListeners;

use LumaricSearch\Services\SearchService;
use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Plugin\Log\Loggable;

/**
 * Sends conversion events to the Lumaric API when an order is placed.
 *
 * Iterates over all order items, reads the click tokens that were stored on the
 * basket items by BasketEventListener, and calls SearchService::trackConversions.
 *
 * Mirrors the behaviour of Shopware's OrderSubscriber.
 */
class OrderEventListener
{
    use Loggable;

    /** @var SearchService */
    private $searchService;

    public function __construct(
        SearchService $searchService
    ) {
        $this->searchService = $searchService;
    }

    public function handleOrderCreated(OrderCreated $event): void
    {
        $order = $event->getOrder();
        if (!$order) return;

        $orderId   = (string) ($order->id ?? '');
        $orderItems = $order->orderItems ?? [];

        $lineItemsData = [];
        foreach ($orderItems as $item) {
            // orderItem properties: id, orderItemName, quantity, properties, ...
            $itemId = (int) ($item->id ?? 0);

            // Click tokens were serialized into order item properties by plentymarkets
            // when converting the basket. The property value must be a JSON string
            // containing { lumaricClickTokens: [...] }.
            //
            // In plentymarkets the exact field depends on how you transport custom
            // basket item data to the order.  Adjust the property name below to
            // match your workflow (e.g. a custom order property or a serialised
            // orderItem.orderProperties entry).
            $customData = [];
            if (isset($item->customData) && $item->customData) {
                $decoded = json_decode((string) $item->customData, true);
                if (is_array($decoded)) $customData = $decoded;
            }

            // Also check orderProperties array for the tokens
            if ($customData === [] && isset($item->orderProperties) && is_array($item->orderProperties)) {
                foreach ($item->orderProperties as $prop) {
                    if (($prop->propertyId ?? null) === SearchService::CLICK_TOKENS_PARAMETER) {
                        $decoded = json_decode((string) ($prop->value ?? '[]'), true);
                        if (is_array($decoded)) {
                            $customData[SearchService::CLICK_TOKENS_PARAMETER] = $decoded;
                        }
                        break;
                    }
                }
            }

            $tokens = $customData[SearchService::CLICK_TOKENS_PARAMETER] ?? [];
            if (!empty($tokens)) {
                $lineItemsData[$itemId] = ['clickTokens' => (array) $tokens];
            }
        }

        if ($lineItemsData !== []) {
            $this->searchService->trackConversions($lineItemsData, $orderId);
        }
    }
}
