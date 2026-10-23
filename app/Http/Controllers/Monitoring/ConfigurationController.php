<?php

namespace App\Http\Controllers\Monitoring;

use App\Configuration\ConfigurationExporter;
use App\Configuration\ConfigurationFormat;
use App\Configuration\ConfigurationImporter;
use App\Configuration\ConfigurationReader;
use App\Configuration\ImportBlocked;
use App\Configuration\InvalidConfigurationFile;
use App\Configuration\PendingImport;
use App\Data\Pages\ConfigurationImportPageData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

class ConfigurationController extends Controller
{
    public function download(Team $current_team, ConfigurationExporter $exporter): HttpResponse
    {
        return response($exporter->toJson($exporter->export($current_team)), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $exporter->fileName($current_team)),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function show(Request $request, Team $current_team, PendingImport $pending, ConfigurationImporter $importer): Response
    {
        $stored = $pending->get($current_team, $request->user());

        return Inertia::render('monitoring/configuration/Import', [
            'page' => new ConfigurationImportPageData(
                preview: $stored === null ? null : $importer->preview($current_team, $stored['document'], $stored['fileName']),
                uploadId: $stored['id'] ?? null,
            ),
        ]);
    }

    public function upload(Request $request, Team $current_team, ConfigurationReader $reader, PendingImport $pending): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:'.intdiv(ConfigurationFormat::MAX_BYTES, 1024)]],
            [
                'file.required' => __('Choose a configuration file.'),
                'file.file' => __('Choose a configuration file.'),
                'file.max' => __('This file is larger than 1 MB.'),
            ],
        );

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $document = $reader->read((string) $file->get());
        } catch (InvalidConfigurationFile $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $pending->put($current_team, $request->user(), $document, Str::limit($file->getClientOriginalName(), 120));

        return to_route('configuration.show', ['current_team' => $current_team->slug]);
    }

    public function discard(Request $request, Team $current_team, PendingImport $pending): RedirectResponse
    {
        $user = $request->user();

        if ($request->input('uploadId') === ($pending->get($current_team, $user)['id'] ?? null)) {
            $pending->forget($current_team, $user);
        }

        return to_route('configuration.show', ['current_team' => $current_team->slug]);
    }

    public function apply(Request $request, Team $current_team, PendingImport $pending, ConfigurationImporter $importer): RedirectResponse
    {
        $request->validate(['update' => ['boolean'], 'uploadId' => ['nullable', 'string']]);

        $user = $request->user();
        $stored = $pending->get($current_team, $user);

        if ($stored === null) {
            throw ValidationException::withMessages(['file' => __('Upload the file again: its preview has expired.')]);
        }

        if ($stored['id'] !== $request->input('uploadId')) {
            throw ValidationException::withMessages(['file' => __('Another file was uploaded since: check its preview and import again.')]);
        }

        try {
            $summary = $importer->apply($current_team, $stored['document'], $request->boolean('update'));
        } catch (ImportBlocked $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $pending->forget($current_team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $summary->message()]);

        return to_route('applications.index', ['current_team' => $current_team->slug]);
    }
}
