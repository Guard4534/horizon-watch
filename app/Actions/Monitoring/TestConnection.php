<?php

namespace App\Actions\Monitoring;

use App\Data\Monitoring\ConnectionResultData;
use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;
use RuntimeException;
use Throwable;

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
            horizonStatus: HorizonStatus::tryFrom($probe->status),
            masterCount: $probe->masterCount,
            latencyMs: $probe->latencyMs,
            error: null,
        );
    }
}
