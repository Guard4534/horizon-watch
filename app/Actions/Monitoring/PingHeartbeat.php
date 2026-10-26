<?php

namespace App\Actions\Monitoring;

use App\Externals\Heartbeat\HeartbeatClient;
use App\Monitoring\PanelHealth;
use Illuminate\Support\Facades\Log;

class PingHeartbeat
{
    public function __construct(private PanelHealth $health, private HeartbeatClient $client) {}

    public function handle(): bool
    {
        $url = config()->string('horizon-watch.health.heartbeat_url');

        if ($url === '') {
            return false;
        }

        $report = $this->health->report(countFailedJobs: false);

        if (! $report->healthy()) {
            Log::warning('The heartbeat was not sent because the panel is not healthy.', [
                'database' => $report->database,
                'scheduler_seconds_ago' => $report->schedulerSecondsAgo,
                'oldest_wait_seconds' => $report->oldestWaitSeconds(),
            ]);

            return false;
        }

        $error = $this->client->ping($url);

        if ($error !== null) {
            Log::warning('The heartbeat could not be delivered.', ['error' => $error->value]);

            return false;
        }

        return true;
    }
}
