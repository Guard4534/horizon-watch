<?php

namespace Database\Seeders;

use App\Actions\Alerts\RegenerateWebhookSecret;
use App\Actions\Setup\CompleteSetup;
use App\Data\Auth\SetupData;
use App\Enums\EnvironmentColor;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SyntheticAlerts;
use Database\Seeders\Support\SyntheticReadings;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
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
     * @var array<string, array<string, int>>
     */
    private const RULE_OVERRIDES = [
        'production' => ['horizon.master_inactive' => 2, 'queue.pending' => 5000, 'queue.max_wait' => 90],
        'preprod' => ['horizon.master_inactive' => 10],
        'worker-batch' => ['job.runtime' => 900, 'workers.missing' => 2],
    ];

    public function run(): void
    {
        $admin = app(CompleteSetup::class)->handle(new SetupData(
            name: 'Admin',
            email: 'admin@example.com',
            password: 'password',
            organization: 'Example Organization',
        ));

        $team = $admin->currentTeam;
        $this->seedDemoReadings($this->seedMockupOrganization($team, polled: false));
        (new SyntheticAlerts)->seed($team, $admin);
        $this->seedLocalHorizon($team);
    }

    /**
     * @param  list<Environment>  $environments
     */
    private function seedDemoReadings(array $environments): void
    {
        $readings = new SyntheticReadings;
        $until = CarbonImmutable::now();

        foreach ($environments as $environment) {
            $readings->seed($environment, $until, stepMinutes: 3);
        }
    }

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
     * @return list<Environment>
     */
    public function seedMockupOrganization(Team $team, bool $polled = true): array
    {
        $environments = [];

        foreach (self::APPLICATIONS as $name => [$host, $environmentNames]) {
            $application = Application::factory()->for($team)->create(['name' => $name, 'host' => $host]);

            foreach ($environmentNames as $environmentName) {
                $hasBasicAuth = in_array($environmentName, ['production', 'preprod'], true);

                $environments[] = Environment::factory()->for($application)->create([
                    'name' => $environmentName,
                    'color' => self::COLORS[$environmentName],
                    'horizon_url' => 'https://'.($environmentName === 'production' ? '' : "{$environmentName}.").$host.'/horizon',
                    'basic_auth_user' => $hasBasicAuth ? 'horizon-bot' : null,
                    'basic_auth_password' => $hasBasicAuth ? 'change-me' : null,
                    'poll_interval_seconds' => 15,
                    'polling_enabled' => $polled,
                ]);
            }
        }

        $this->seedAlertSettings($team);

        return $environments;
    }

    private function seedAlertSettings(Team $team): void
    {
        foreach (self::RULE_OVERRIDES as $scope => $thresholds) {
            foreach ($thresholds as $metric => $threshold) {
                $team->alertRules()->create([
                    'scope' => $scope,
                    'metric' => $metric,
                    'threshold' => $threshold,
                ]);
            }
        }

        $team->notificationSetting()->create([
            'recipients' => ['ops@example.com', 'oncall@example.com'],
            'webhook_url' => 'https://hooks.example.com/horizon',
            'webhook_secret' => RegenerateWebhookSecret::newSecret(),
            'quiet_from' => '23:00',
            'quiet_to' => '07:00',
            'timezone' => NotificationSetting::DEFAULT_TIMEZONE,
            'repeat_minutes' => 30,
        ]);

        app()->forgetScopedInstances();
    }
}
