<?php

namespace App\Queries;

use App\Data\Monitoring\RuleScopeData;
use App\Data\Pages\AlertRulesPageData;
use App\Data\Pages\NotificationSummaryData;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
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
            notificationSummary: NotificationSummaryData::of($settings),
            notifications: $canManage ? $settings : null,
            canManage: $canManage,
            newWebhookSecret: $canManage ? $this->newWebhookSecret($session) : null,
        );
    }

    public static function flashNewWebhookSecret(Session $session, #[SensitiveParameter] string $secret): void
    {
        $session->flash(self::NEW_WEBHOOK_SECRET, Crypt::encryptString($secret));
    }

    private function newWebhookSecret(Session $session): ?string
    {
        $sealed = $session->get(self::NEW_WEBHOOK_SECRET);

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
