<?php

namespace App\Console\Commands;

use App\Configuration\ConfigurationExporter;
use App\Models\Team;
use ErrorException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('applications:export {organization : Slug of the organization} {--output= : Write the file here instead of to standard output}')]
#[Description('Export the applications, environments and alert rules of an organization as a configuration file, without any secret')]
class ExportConfiguration extends Command
{
    public function handle(ConfigurationExporter $exporter): int
    {
        $slug = (string) $this->argument('organization');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->error("No organization uses the slug {$slug}.");

            return self::FAILURE;
        }

        $json = $exporter->toJson($exporter->export($team));
        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->output->write($json, false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        try {
            $written = file_put_contents($output, $json);
        } catch (ErrorException) {
            $written = false;
        }

        if ($written === false) {
            $this->error("Cannot write {$output}.");

            return self::FAILURE;
        }

        $this->info("Exported the configuration of {$team->name} to {$output}.");

        return self::SUCCESS;
    }
}
