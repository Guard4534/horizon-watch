<?php

namespace App\Actions\Monitoring;

use App\Data\Monitoring\ConnectionResultData;
use App\Data\Monitoring\ReadingProblemData;
use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;
use App\Support\SafeReport;
use Throwable;

class TestConnection
{
    public function __construct(private HorizonReader $reader) {}

    public function handle(HorizonTarget $target): ConnectionResultData
    {
        $inClear = $target->sendsCredentialsInClear();

        try {
            $probe = $this->reader->probe($target);
        } catch (HorizonReadFailed $exception) {
            return ConnectionResultData::failed(
                $exception->reason,
                ReadingProblemData::fromFailure($exception),
                $exception->reason === ReadingError::NotHorizon ? $target->suggestedDashboardUrl() : null,
                $inClear,
            );
        } catch (Throwable $exception) {
            SafeReport::of('The Horizon reader', $exception, class_basename(HorizonReadFailed::class));

            return ConnectionResultData::failed(ReadingError::Unreachable, credentialsInClear: $inClear);
        }

        return new ConnectionResultData(
            reachable: true,
            horizonStatus: HorizonStatus::tryFrom($probe->status),
            masterCount: $probe->masterCount,
            latencyMs: $probe->latencyMs,
            error: null,
            credentialsInClear: $inClear,
        );
    }
}
