<?php

namespace App\Console\Commands;

use App\Actions\Monitoring\PingHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('horizon-watch:heartbeat')]
#[Description('Call the heartbeat address while the panel is healthy')]
class SendHeartbeat extends Command
{
    public function handle(PingHeartbeat $heartbeat): int
    {
        if (config()->string('horizon-watch.health.heartbeat_url') === '') {
            $this->components->warn('No heartbeat address is configured.');

            return self::SUCCESS;
        }

        if (! $heartbeat->handle()) {
            $this->components->error('The heartbeat was not sent: see the log.');

            return self::FAILURE;
        }

        $this->components->info('The heartbeat was sent.');

        return self::SUCCESS;
    }
}
