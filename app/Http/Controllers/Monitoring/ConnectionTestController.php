<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\TestConnection;
use App\Data\Applications\TestConnectionData;
use App\Externals\Horizon\HorizonTarget;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConnectionTestController extends Controller
{
    public function environment(
        Request $request,
        Team $current_team,
        Environment $environment,
        MonitoringRepository $monitoring,
        TestConnection $testConnection,
    ): JsonResponse {
        if ($request->request->count() === 0) {
            abort_if($monitoring->environment($current_team, $environment->slug) === null, 404);
            Gate::authorize('testConnection', $environment);

            $target = HorizonTarget::fromEnvironment($environment);
        } else {
            Gate::authorize('update', $environment);

            $data = TestConnectionData::from($request);

            if ($data->changesCredentialsOf($environment)) {
                Gate::authorize('manageCredentials', $environment);
            }

            $target = $data->target($environment);
        }

        return response()->json($testConnection->handle($target)->toArray());
    }

    public function application(Request $request, Team $current_team, TestConnection $testConnection): JsonResponse
    {
        Gate::authorize('create', [Application::class, $current_team]);

        $data = TestConnectionData::from($request);

        return response()->json($testConnection->handle($data->target())->toArray());
    }
}
