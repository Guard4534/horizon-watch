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

/**
 * JSON for the ConnectionTest component's own request, not an Inertia
 * visit. A result is always 200, reachable or not: the status codes are
 * left to authorization (403/404), validation (422) and the limiter (429).
 */
class ConnectionTestController extends Controller
{
    /**
     * Two modes on one route, told apart by whether there is a body.
     *
     * Without one, the saved address and credentials are probed. That is
     * the "test connection" permission a member holds, so it is limited to
     * an environment the person watches — the detail page's own lookup,
     * checked before the permission, so a hidden environment answers 404 to
     * everyone who cannot see it, as its page does.
     *
     * With one (the edit form, before saving), the address is whatever was
     * typed. Letting a member do that would let them aim the server at any
     * URL through an environment they can merely see, so it takes the same
     * permission as saving the form. A blank password there means the one
     * on file, which never leaves the server.
     */
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

            $target = TestConnectionData::from($request)->target($environment);
        }

        return response()->json($testConnection->handle($target)->toArray());
    }

    /**
     * An address that is not saved anywhere yet: the wizard's rows and the
     * add-environment form, so the permission is the one that creates them.
     */
    public function application(Request $request, Team $current_team, TestConnection $testConnection): JsonResponse
    {
        Gate::authorize('create', [Application::class, $current_team]);

        $data = TestConnectionData::from($request);

        return response()->json($testConnection->handle($data->target())->toArray());
    }
}
