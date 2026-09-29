<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\UpdateAlertEmails;
use App\Data\Settings\UpdateAlertEmailsData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AlertEmailsController extends Controller
{
    public function __invoke(Request $request, UpdateAlertEmailsData $data, UpdateAlertEmails $updateAlertEmails): RedirectResponse
    {
        $updateAlertEmails->handle($request->user(), $data->alertEmails);

        $this->success($data->alertEmails
            ? __('Alert emails turned on.')
            : __('Alert emails turned off.'));

        return back();
    }
}
