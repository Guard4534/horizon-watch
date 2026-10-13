<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\EndOtherSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OtherSessionsDestroyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class OtherSessionsController extends Controller
{
    public function destroy(OtherSessionsDestroyRequest $request, EndOtherSessions $endOtherSessions): RedirectResponse
    {
        Auth::logoutOtherDevices($request->string('password')->value());

        $endOtherSessions->handle($request->user(), $request->session()->getId());

        $this->success(__('Other sessions logged out.'));

        return back();
    }
}
