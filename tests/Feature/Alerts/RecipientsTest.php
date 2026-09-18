<?php

use App\Alerts\Recipients;
use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentColor;
use App\Enums\Locale;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Tests\Support\AlertTeam;

beforeEach(function () {
    config(['app.locale' => 'en']);
    $this->team = Team::factory()->create();
    $application = Application::factory()->for($this->team)->create(['name' => 'Shop']);
    $this->production = Environment::factory()->for($application)->production()->create();
    $this->staging = Environment::factory()->for($application)->staging()->create();

    $this->admin = AlertTeam::member($this->team, 'admin@example.com', TeamRole::Admin, locale: Locale::It);
    $this->nonProduction = AlertTeam::member($this->team, 'dev@example.com', visibility: MemberVisibility::NonProduction);
    $this->viewer = AlertTeam::member($this->team, 'viewer@example.com', TeamRole::Viewer, MemberVisibility::Manual, grants: [$this->production]);
    $this->silent = AlertTeam::member($this->team, 'silent@example.com', alertEmails: false);

    $other = Team::factory()->create();
    Application::factory()->for($other)->create();
    AlertTeam::member($other, 'stranger@example.com');
    $this->viewer->teams()->attach($other, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);

    NotificationSetting::factory()->for($this->team)->create([
        'recipients' => ['ops@example.com', 'ADMIN@example.com', 'Oncall@Example.com'],
    ]);
});

function recipientEmails(array $recipients): array
{
    return array_map(fn (array $recipient) => $recipient['email'], $recipients);
}

test('an alert goes to the opted-in members who see its environment and to the extra addresses', function () {
    $production = Alert::factory()->for($this->production)->create(['metric' => AlertRuleMetric::QueuePending]);
    $staging = Alert::factory()->for($this->staging)->create(['metric' => AlertRuleMetric::QueuePending]);

    expect(recipientEmails(app(Recipients::class)->forAlert($production)))
        ->toBe(['admin@example.com', 'viewer@example.com', 'ops@example.com', 'oncall@example.com'])
        ->and(recipientEmails(app(Recipients::class)->forAlert($staging)))
        ->toBe(['admin@example.com', 'dev@example.com', 'ops@example.com', 'oncall@example.com']);
});

test('a member keeps their own language and wins over the same extra address', function () {
    $alert = Alert::factory()->for($this->production)->create();

    $recipients = collect(app(Recipients::class)->forAlert($alert))->keyBy('email');

    expect($recipients['admin@example.com']['locale'])->toBe('it')
        ->and($recipients['admin@example.com']['user']?->is($this->admin))->toBeTrue()
        ->and($recipients['viewer@example.com']['locale'])->toBe('en')
        ->and($recipients['ops@example.com']['locale'])->toBe('en')
        ->and($recipients['ops@example.com']['user'])->toBeNull();

    config(['app.locale' => 'it']);

    $recipients = collect(app(Recipients::class)->forAlert($alert))->keyBy('email');

    expect($recipients['ops@example.com']['locale'])->toBe('it')
        ->and($recipients['viewer@example.com']['locale'])->toBe('it');
});

test('nobody gets an email when the effective rule has email off', function () {
    AlertRule::factory()->for($this->team)->forScope('Staging')->inheriting()->create([
        'metric' => AlertRuleMetric::QueuePending,
        'notify_email' => false,
    ]);

    $staging = Alert::factory()->for($this->staging)->create(['metric' => AlertRuleMetric::QueuePending]);
    $production = Alert::factory()->for($this->production)->create(['metric' => AlertRuleMetric::QueuePending]);
    $runtime = Alert::factory()->for($this->production)->create(['metric' => AlertRuleMetric::JobRuntime]);

    expect(app(Recipients::class)->forAlert($staging))->toBe([])
        ->and(app(Recipients::class)->forAlert($production))->not->toBe([])
        ->and(app(Recipients::class)->forAlert($runtime))->toBe([]);
});

test('an alert of a deleted environment goes only to the extra addresses', function () {
    $alert = Alert::factory()->create([
        'environment_id' => null,
        'team_id' => $this->team->id,
        'application_name' => 'Shop',
        'environment_name' => 'gone',
        'environment_color' => EnvironmentColor::Staging,
        'metric' => AlertRuleMetric::QueuePending,
    ]);

    expect(recipientEmails(app(Recipients::class)->forAlert($alert)))->toBe(['admin@example.com', 'ops@example.com', 'oncall@example.com']);
});

test('without settings only members are recipients', function () {
    NotificationSetting::query()->delete();
    $alert = Alert::factory()->for($this->production)->create();

    expect(recipientEmails(app(Recipients::class)->forAlert($alert)))->toBe(['admin@example.com', 'viewer@example.com']);
});

test('a deleted organization has no recipients', function () {
    $alert = Alert::factory()->for($this->production)->create();
    $this->team->delete();

    expect(app(Recipients::class)->forAlert($alert->fresh()))->toBe([]);
});

test('the digest lists each recipient with the environments they see', function () {
    $recipients = collect(app(Recipients::class)->forDigest($this->team))->keyBy('email');

    expect($recipients->keys()->all())->toBe(['admin@example.com', 'dev@example.com', 'viewer@example.com', 'ops@example.com', 'oncall@example.com'])
        ->and($recipients['admin@example.com']['environmentIds'])->toBeNull()
        ->and($recipients['admin@example.com']['locale'])->toBe('it')
        ->and($recipients['dev@example.com']['environmentIds'])->toBe([$this->staging->id])
        ->and($recipients['viewer@example.com']['environmentIds'])->toBe([$this->production->id])
        ->and($recipients['ops@example.com']['environmentIds'])->toBeNull()
        ->and($recipients['ops@example.com']['user'])->toBeNull();
});

test('a manual member with no grant gets an empty list, not every environment', function () {
    AlertTeam::member($this->team, 'nothing@example.com', visibility: MemberVisibility::Manual);

    $recipients = collect(app(Recipients::class)->forDigest($this->team))->keyBy('email');

    expect($recipients['nothing@example.com']['environmentIds'])->toBe([]);
});

test('an address that is also an extra address reaches every environment, as a member in their language', function () {
    NotificationSetting::query()->whereKey($this->team->id)->update(['recipients' => json_encode(['ops@example.com', 'DEV@example.com'])]);
    $production = Alert::factory()->for($this->production)->create(['metric' => AlertRuleMetric::QueuePending]);
    $gone = Alert::factory()->create([
        'environment_id' => null,
        'team_id' => $this->team->id,
        'application_name' => 'Shop',
        'environment_name' => 'gone',
        'environment_color' => EnvironmentColor::Staging,
        'metric' => AlertRuleMetric::QueuePending,
    ]);

    $digest = collect(app(Recipients::class)->forDigest($this->team))->keyBy('email');
    $alert = collect(app(Recipients::class)->forAlert($production))->keyBy('email');

    expect($digest['dev@example.com']['environmentIds'])->toBeNull()
        ->and($digest['dev@example.com']['user']?->is($this->nonProduction))->toBeTrue()
        ->and($digest['viewer@example.com']['environmentIds'])->toBe([$this->production->id])
        ->and($alert->keys()->all())->toBe(['admin@example.com', 'dev@example.com', 'viewer@example.com', 'ops@example.com'])
        ->and($alert['dev@example.com']['user']?->is($this->nonProduction))->toBeTrue()
        ->and(recipientEmails(app(Recipients::class)->forAlert($gone)))->toBe(['dev@example.com', 'ops@example.com']);
});

test('extra addresses are checked at send time with the rule that saved them, and a skipped one is logged by count only', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
        $logged[] = [$message->level, $message->message, $message->context];
    });
    NotificationSetting::query()->whereKey($this->team->id)->update(['recipients' => json_encode(['josé@example.com', 'not-an-address', 'ops@example.com'])]);

    expect(Validator::make(['address' => 'josé@example.com'], ['address' => 'email:rfc'])->passes())->toBeTrue()
        ->and(recipientEmails(app(Recipients::class)->forDigest($this->team)))->toBe(['admin@example.com', 'dev@example.com', 'viewer@example.com', 'josé@example.com', 'ops@example.com'])
        ->and(app(Recipients::class)->extraAddress($this->team, Recipients::addressKey('josé@example.com')))->toBe('josé@example.com')
        ->and(app(Recipients::class)->extraAddress($this->team, Recipients::addressKey('not-an-address')))->toBeNull()
        ->and($logged)->not->toBeEmpty()
        ->and($logged[0])->toBe(['warning', 'Invalid extra alert addresses skipped.', ['team' => $this->team->id, 'count' => 1]])
        ->and(json_encode($logged))->not->toContain('not-an-address');
});
