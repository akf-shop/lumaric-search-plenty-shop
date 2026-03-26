<?php declare(strict_types=1);

namespace LumaricSearch\Providers;

use LumaricSearch\Crons\ExportProductsCron;
use LumaricSearch\EventListeners\BasketEventListener;
use LumaricSearch\EventListeners\OrderEventListener;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Cron\Services\CronContainer;
use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\ServiceProvider;

class LumaricSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(LumaricSearchRouteServiceProvider::class);
    }

    public function boot(Dispatcher $events, CronContainer $scheduler): void
    {
        // ── Scheduled exports ─────────────────────────────────────────────────
        // Exports all active variations and uploads them to the Lumaric API once
        // per hour (equivalent to the two-task pattern used in Shopware).
        $scheduler->add(CronContainer::HOURLY, ExportProductsCron::class);

        // ── Cart: persist click tokens on basket items ─────────────────────────
        $events->listen(AfterBasketItemAdd::class, BasketEventListener::class . '@handleAfterBasketItemAdd');

        // ── Order: send conversion events to Lumaric ───────────────────────────
        $events->listen(OrderCreated::class, OrderEventListener::class . '@handleOrderCreated');
    }
}
