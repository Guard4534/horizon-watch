<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ChangeLocale;
use App\Data\Settings\UpdateLocaleData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(UpdateLocaleData $data, Request $request, ChangeLocale $changeLocale): RedirectResponse
    {
        $changeLocale->handle($request->session(), $request->user(), $data->locale);

        return back();
    }
}
