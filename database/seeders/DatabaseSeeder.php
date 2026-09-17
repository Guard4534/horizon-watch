<?php

namespace Database\Seeders;

use App\Actions\Setup\CompleteSetup;
use App\Data\Auth\SetupData;
use App\Enums\EnvironmentColor;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SyntheticReadings;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately no WithoutModelEvents: Team, Application and Environment
    // all generate their slug from a "creating" model event, which that
    // trait would silently suppress, leaving every seeded slug null.

    /**
     * The organization the phase 1 mockup showed: application name => [host,
     * environment names]. Kept identical to the old FakeMonitoringRepository
     * fixture so the resulting slugs still match SyntheticReadings' fixed
     * incidents.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    private const APPLICATIONS = [
        'Fatturaomatic' => ['fatturaomatic.example.com', ['production', 'preprod', 'staging', 'develop']],
        'Acme Shop' => ['shop.example.com', ['production', 'staging', 'develop', 'demo']],
        'Logistics Hub' => ['logistics.example.com', ['production', 'worker-batch', 'staging']],
        'CRM Bridge' => ['crm-bridge.example.com', ['production', 'preprod', 'testing']],
        'Mailer Service' => ['mailer.example.net', ['production', 'worker-batch', 'staging', 'develop']],
        'Media Encoder' => ['media.example.net', ['production', 'worker-batch', 'testing']],
        'Partner API' => ['api.example.org', ['production', 'staging', 'demo']],
        'Billing Sync' => ['billing.example.com', ['production', 'preprod', 'develop']],
        'Internal Tools' => ['tools.example.com', ['production', 'staging']],
    ];

    /**
     * @var array<string, EnvironmentColor>
     */
    private const COLORS = [
        'production' => EnvironmentColor::Prod,
        'preprod' => EnvironmentColor::Preprod,
        'staging' => EnvironmentColor::Staging,
        'develop' => EnvironmentColor::Develop,
        'demo' => EnvironmentColor::Demo,
        'worker-batch' => EnvironmentColor::Worker,
        'testing' => EnvironmentColor::Testing,
    ];

    /**
     * Seed the application's database for development, so the wall isn't
     * empty. Never runs in production: the production entrypoint never
     * calls db:seed.
     */
    public function run(): void
    {
        $admin = app(CompleteSetup::class)->handle(new SetupData(
            name: 'Admin',
            email: 'admin@example.com',
            password: 'password',
            organization: 'Example Organization',
        ));

        $team = $admin->currentTeam;
        $this->seedMockupOrganization($team);
        $this->seedDemoReadings($team);
        $this->seedLocalHorizon($team);
    }

    /**
     * Nothing answers at the demo URLs, so the demo environments are never
     * polled: they get a day of invented readings instead, one every three
     * minutes so every bucket of the three-hour chart (225 seconds) holds
     * at least one.
     */
    private function seedDemoReadings(Team $team): void
    {
        $readings = new SyntheticReadings;
        $until = CarbonImmutable::now();

        Environment::query()
            ->where('team_id', $team->id)
            ->with('application')
            ->each(function (Environment $environment) use ($readings, $until) {
                $environment->update(['polling_enabled' => false]);
                $readings->seed($environment, $until, stepMinutes: 3);
            });
    }

    /**
     * A real Horizon to poll during development, when one is configured.
     * It gets no synthetic readings: the poller writes them, and nothing it
     * reads belongs in the repository. Inside Sail the host machine is
     * host.docker.internal, e.g. http://host.docker.internal:8080/horizon.
     */
    private function seedLocalHorizon(Team $team): void
    {
        $url = config('horizon-watch.demo_horizon_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        $application = Application::factory()->for($team)->create(['name' => 'Local Horizon', 'host' => 'localhost']);

        Environment::factory()->for($application)->create([
            'name' => 'local',
            'color' => EnvironmentColor::Develop,
            'horizon_url' => $url,
            'basic_auth_user' => null,
            'basic_auth_password' => null,
            'poll_interval_seconds' => 15,
            'polling_enabled' => true,
        ]);
    }

    /**
     * Populate a team with the 9 applications and 29 environments of the
     * phase 1 mockup. Also used by the Monitoring feature tests, so their
     * fixture is exactly the configuration development and CI both see;
     * readings are left to each test (run() adds the demo ones).
     */
    public function seedMockupOrganization(Team $team): void
    {
        foreach (self::APPLICATIONS as $name => [$host, $environmentNames]) {
            $application = Application::factory()->for($team)->create(['name' => $name, 'host' => $host]);

            foreach ($environmentNames as $environmentName) {
                // Only production and preprod carry basic auth, as in the phase 1 data.
                $hasBasicAuth = in_array($environmentName, ['production', 'preprod'], true);

                Environment::factory()->for($application)->create([
                    'name' => $environmentName,
                    'color' => self::COLORS[$environmentName],
                    'horizon_url' => 'https://'.($environmentName === 'production' ? '' : "{$environmentName}.").$host.'/horizon',
                    'basic_auth_user' => $hasBasicAuth ? 'horizon-bot' : null,
                    'basic_auth_password' => $hasBasicAuth ? 'change-me' : null,
                    'poll_interval_seconds' => 15,
                ]);
            }
        }
    }
}
