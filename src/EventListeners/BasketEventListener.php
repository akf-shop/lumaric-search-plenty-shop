<?php declare(strict_types=1);

namespace LumaricSearch\EventListeners;

use LumaricSearch\Services\SearchService;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Plugin\Http\Request;

/**
 * Persists Lumaric click tokens on basket items.
 *
 * When a product is added to the cart, the frontend sends the click token it
 * received from the search-results click-tracking response as a request
 * parameter (lumaricClickToken).  We read that token here and store it in the
 * basket item's customData JSON so that the OrderEventListener can later ship
 * it to the Lumaric conversion endpoint.
 *
 * Mirrors the behaviour of Shopware's CartSubscriber.
 */
class BasketEventListener
{
    /** @var Request */
    private $request;

    public function __construct(
        Request $request
    ) {
        $this->request = $request;
    }

    public function handleAfterBasketItemAdd(AfterBasketItemAdd $event): void
    {
        $clickToken = $this->request->input(SearchService::CLICK_TOKEN_PARAMETER);
        if (!$clickToken || !is_string($clickToken)) return;

        /** @var BasketItem $basketItem */
        $basketItem = $event->getBasketItem();

        // customData is a JSON-encoded string on BasketItem.
        $customData = [];
        $raw = $basketItem->customData ?? '';
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $customData = $decoded;
        }

        /** @var list<string> $tokens */
        $tokens   = $customData[SearchService::CLICK_TOKENS_PARAMETER] ?? [];
        if (!is_array($tokens)) $tokens = [];

        $tokens = array_values(array_unique(array_merge($tokens, [$clickToken])));

        $customData[SearchService::CLICK_TOKENS_PARAMETER] = $tokens;
        $basketItem->customData = (string) json_encode($customData);
    }
}
