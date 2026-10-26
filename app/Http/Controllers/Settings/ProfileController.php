<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\DeleteAccount;
use App\Actions\Settings\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request, DeleteAccount $deleteAccount): Response
    {
        return Inertia::render('settings/Profile', [
            'alertEmails' => (bool) $request->user()?->alert_emails,
            'accountDeletion' => $deleteAccount->summary($request->user()),
        ]);
    }

    public function update(ProfileUpdateRequest $request, UpdateProfile $updateProfile): RedirectResponse
    {
        $updateProfile->handle($request->user(), $request->string('name')->value(), $request->string('email')->value());

        $this->success(__('Profile updated.'));

        return to_route('profile.edit');
    }

    public function destroy(ProfileDeleteRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $deleteAccount->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
