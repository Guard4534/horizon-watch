<?php

namespace App\Queries;

use App\Data\Monitoring\RuleScopeData;
use App\Data\Pages\AlertRulesPageData;
use App\Data\Pages\NotificationSummaryData;
use App\Models\AlertRule;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

class AlertRulesQuery
{
    private const string NEW_WEBHOOK_SECRET = 'alert-settings.new-webhook-secret';

    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, User $viewer, string $scope, Session $session): AlertRulesPageData
    {
        $scopes = $this->monitoring->ruleScopes($team);

        abort_unless(in_array($scope, array_map(fn (RuleScopeData $item) => $item->id, $scopes), true), 404);

        $settings = $this->monitoring->notificationSettings($team);
        $canManage = Gate::forUser($viewer)->allows('manageAlertRules', $team);

        return new AlertRulesPageData(
            scopes: $scopes,
            scope: $scope,
            rules: $this->monitoring->alertRules($team, $scope),
            organizationRules: $scope === AlertRule::ORGANIZATION ? [] : $this->monitoring->alertRules($team, AlertRule::ORGANIZATION),
            notificationSummary: NotificationSummaryData::of($settings),
            notifications: $canManage ? $settings : null,
            canManage: $canManage,
            newWebhookSecret: $canManage ? $this->newWebhookSecret($team, $session) : null,
            repeatChoices: array_values(array_map(intval(...), config()->array('horizon-watch.notifications.repeat_minutes'))),
            maxRecipients: config()->integer('horizon-watch.notifications.max_recipients'),
            timezones: $canManage ? DateTimeZone::listIdentifiers() : [],
        );
    }

    public static function flashNewWebhookSecret(Team $team, Session $session, #[SensitiveParameter] string $secret): void
    {
        $session->flash(self::newWebhookSecretKey($team), Crypt::encryptString($secret));
    }

    private static function newWebhookSecretKey(Team $team): string
    {
        return self::NEW_WEBHOOK_SECRET.'.'.$team->id;
    }

    private function newWebhookSecret(Team $team, Session $session): ?string
    {
        $sealed = $session->get(self::newWebhookSecretKey($team));

        if (! is_string($sealed)) {
            return null;
        }

        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }
    }
}
