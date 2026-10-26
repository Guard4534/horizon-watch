<?php

namespace App\Console\Commands;

use App\Configuration\ConfigurationImporter;
use App\Configuration\ConfigurationReader;
use App\Configuration\ImportBlocked;
use App\Configuration\InputSource;
use App\Configuration\InvalidConfigurationFile;
use App\Data\Configuration\ImportItemData;
use App\Enums\ImportItemKind;
use App\Enums\ImportItemStatus;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('applications:import {organization : Slug of the organization} {path : The configuration file, or - to read standard input} {--update : Update the items that exist and differ from the file} {--dry-run : Print the preview and change nothing}')]
#[Description('Import applications, environments and alert rules from a configuration file; nothing is ever deleted')]
class ImportConfiguration extends Command
{
    public function handle(InputSource $input, ConfigurationReader $reader, ConfigurationImporter $importer): int
    {
        $slug = (string) $this->argument('organization');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->error("No organization uses the slug {$slug}.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $update = (bool) $this->option('update');

        try {
            $document = $reader->read($input->read($path));
        } catch (InvalidConfigurationFile $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $preview = $importer->preview($team, $document, $path === '-' ? 'stdin' : basename($path));

        $this->table(['Item', 'Status', 'Details'], array_map(fn (ImportItemData $item) => [
            $this->nameOf($item),
            $this->statusOf($item, $update),
            $this->detailsOf($item),
        ], $preview->items));

        if ($preview->blocked) {
            $this->error('The file has invalid items: fix them and run the import again. Nothing was changed.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $summary = $importer->apply($team, $document, $update);
        } catch (ImportBlocked $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($summary->message());

        return self::SUCCESS;
    }

    private function nameOf(ImportItemData $item): string
    {
        return match ($item->kind) {
            ImportItemKind::Application => $item->label,
            ImportItemKind::Environment => $item->application.' / '.$item->label,
            ImportItemKind::AlertRule => 'rule '.$item->label,
        };
    }

    private function statusOf(ImportItemData $item, bool $update): string
    {
        if ($item->status !== ImportItemStatus::Changed) {
            return $item->status->value;
        }

        return $update ? 'changed, will be updated' : 'changed, skipped (use --update)';
    }

    private function detailsOf(ImportItemData $item): string
    {
        $details = $item->errors;

        if ($item->changes !== []) {
            $details[] = 'differs: '.implode(', ', $item->changes);
        }

        if ($item->passwordNeeded) {
            $details[] = 'password needed';
        }

        return implode('; ', $details);
    }
}
