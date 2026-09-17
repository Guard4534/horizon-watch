<?php

namespace App\Actions\Environments;

use App\Data\Applications\EnvironmentFormData;
use App\Externals\Horizon\HorizonTarget;
use App\Models\Environment;
use App\Models\EnvironmentState;
use App\Rules\StoredPasswordStaysWithItsAddress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class UpdateEnvironment
{
    public function handle(Environment $environment, EnvironmentFormData $data): Environment
    {
        if ($data->basicAuthUser !== null
            && ! $data->hasNewPassword()
            && StoredPasswordStaysWithItsAddress::hasStoredPassword($environment)
            && ! EnvironmentFormData::sameAddress($data->horizonUrl, $environment->horizon_url)) {
            throw new LogicException('The stored password cannot follow the environment to a new address.');
        }

        $attributes = [
            'name' => $data->name,
            'color' => $data->color,
            'horizon_url' => $data->horizonUrl,
            'basic_auth_user' => $data->basicAuthUser,
            'poll_interval_seconds' => $data->pollIntervalSeconds,
            'polling_enabled' => $data->pollingEnabled,
        ];

        if ($data->basicAuthUser === null) {
            $attributes['basic_auth_password'] = null;
        } elseif ($data->hasNewPassword()) {
            $attributes['basic_auth_password'] = $data->basicAuthPassword;
        }

        $moved = ! $this->sameEndpoint($data->horizonUrl, $environment->horizon_url);
        $resumed = $data->pollingEnabled && ! $environment->polling_enabled;

        DB::transaction(function () use ($environment, $attributes, $moved, $resumed, $data) {
            $environment->update($attributes);

            if ($moved) {
                EnvironmentState::query()->where('environment_id', $environment->id)->delete();
            }

            $now = CarbonImmutable::now()->startOfSecond();
            $nextPollAt = $moved || $resumed ? $now : $now->addSeconds($data->pollIntervalSeconds);

            Environment::query()
                ->whereKey($environment->id)
                ->where('next_poll_at', '>', $nextPollAt)
                ->toBase()
                ->update(['next_poll_at' => $nextPollAt]);
        });

        return $environment;
    }

    private function sameEndpoint(string $first, string $second): bool
    {
        $path = fn (string $url): string => (string) parse_url((new HorizonTarget(trim($url), null, null))->apiUrl(''), PHP_URL_PATH);

        return EnvironmentFormData::sameAddress($first, $second) && $path($first) === $path($second);
    }
}
