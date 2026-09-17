<?php

namespace App\Actions\Monitoring;

use App\Data\Monitoring\ConnectionResultData;
use App\Enums\ReadingError;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;
use RuntimeException;
use Throwable;

/**
 * One synchronous probe, through the same guard and client as the poller.
 * Writes nothing: a test is not a reading, and an address that was never
 * saved has no environment to write it to.
 */
class TestConnection
{
    public function __construct(private HorizonReader $reader) {}

    public function handle(HorizonTarget $target): ConnectionResultData
    {
        try {
            $probe = $this->reader->probe($target);
        } catch (HorizonReadFailed $exception) {
            return ConnectionResultData::failed($exception->reason);
        } catch (Throwable $exception) {
            // Same containment as PollEnvironment: the reader promises
            // HorizonReadFailed only, and anything else may carry what an
            // HTTP library put in its message (a URL with credentials, a
            // body). Reported by class and place alone, never chained, and
            // the person testing sees "does not answer" instead of a 500.
            report(new RuntimeException(sprintf(
                'The Horizon reader threw %s at %s:%d instead of HorizonReadFailed.',
                $exception::class,
                $exception->getFile(),
                $exception->getLine(),
            )));

            return ConnectionResultData::failed(ReadingError::Unreachable);
        }

        return new ConnectionResultData(
            reachable: true,
            horizonStatus: $probe->status,
            masterCount: $probe->masterCount,
            latencyMs: $probe->latencyMs,
            error: null,
        );
    }
}
