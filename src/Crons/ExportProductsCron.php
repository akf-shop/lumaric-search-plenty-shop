<?php declare(strict_types=1);

namespace LumaricSearch\Crons;

use LumaricSearch\Services\ExportService;
use Plenty\Modules\Cron\Contracts\CronHandler;
use Plenty\Plugin\Log\Loggable;

/**
 * Runs every hour and exports all active variations to the Lumaric Search index.
 *
 * Registered via CronContainer::HOURLY in LumaricSearchServiceProvider.
 * This replaces the two-task (InitSearchProductExport + UploadSearchProductExport)
 * pattern used in Shopware: plentymarkets does not have an async message queue,
 * so the export and upload are performed in a single cron run.
 */
class ExportProductsCron extends CronHandler
{
    use Loggable;

    /** @var ExportService */
    private $exportService;

    public function __construct(
        ExportService $exportService
    ) {
        $this->exportService = $exportService;
    }

    public function handle(): void
    {
        $this->getLogger(__METHOD__)->info('Lumaric Search export started');

        try {
            $this->exportService->exportProducts();
            $this->getLogger(__METHOD__)->info('Lumaric Search export completed');
        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('Lumaric Search export failed', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }
    }
}
