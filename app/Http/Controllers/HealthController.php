<?php

namespace App\Http\Controllers;

use App\Monitoring\PanelHealth;
use App\Support\Version;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(PanelHealth $health): JsonResponse
    {
        $report = $health->report();

        return response()->json([
            'status' => $report->healthy() ? 'ok' : 'failing',
            'version' => Version::current(),
            'checks' => [
                'database' => [
                    'ok' => $report->database,
                ],
                'scheduler' => [
                    'ok' => ! $report->schedulerLate(),
                    'last_run_seconds_ago' => $report->schedulerSecondsAgo,
                ],
                'queues' => [
                    'ok' => $report->database && ! $report->backlogLate(),
                    'oldest_wait_seconds' => (object) $report->queueWaitSeconds,
                    'failed_jobs_last_hour' => $report->failedJobsLastHour,
                ],
            ],
        ], $report->healthy() ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
