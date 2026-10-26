<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\EndOtherSessions;
use App\Data\Auth\PasswordPolicyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordPolicy' => PasswordPolicyData::current(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    public function update(PasswordUpdateRequest $request, EndOtherSessions $endOtherSessions): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Auth::logoutOtherDevices($request->string('password')->value());

        $endOtherSessions->handle($request->user(), $request->session()->getId());

        $this->success(__('Password updated.'));

        return back();
    }
}
