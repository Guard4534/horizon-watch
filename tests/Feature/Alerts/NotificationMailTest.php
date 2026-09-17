<?php

use App\Alerts\EmailPreview;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentColor;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentState;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Notifications\Alerts\AlertNotification;
use App\Notifications\Alerts\ResolvedNotification;
use App\Notifications\Alerts\TestNotification;
use App\Notifications\Alerts\WarningDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:30:00', 'UTC'));
    $this->team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->application = Application::factory()->for($this->team)->create(['name' => 'Shop <b>&</b>']);
    $this->environment = Environment::factory()->for($this->application)->production()->create();
    EnvironmentState::factory()->for($this->environment)->create([
        'nodes' => [
            ['hostname' => 'queue-01', 'status' => 'running', 'workers' => 3, 'supervisors' => 1, 'queues' => 2],
            ['hostname' => 'queue-02', 'status' => 'running', 'workers' => 3, 'supervisors' => 1, 'queues' => 2],
        ],
    ]);
    $this->alert = Alert::factory()->for($this->environment)->critical()->create([
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 2000,
        'unit' => 'job',
        'value' => 4312,
        'opened_at' => CarbonImmutable::parse('2026-09-17 12:02:00', 'UTC'),
    ]);
});

function inMailLocale(string $locale, Closure $call): string
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return (string) $call();
    } finally {
        App::setLocale($previous);
    }
}

function mailHtml(object $notification, string $locale = 'en'): string
{
    return inMailLocale($locale, fn () => $notification->toMail(new AnonymousNotifiable)->render());
}

function mailSubject(object $notification, string $locale = 'en'): string
{
    return inMailLocale($locale, fn () => $notification->toMail(new AnonymousNotifiable)->subject);
}

test('a critical alert email carries the subject, the detail rows and the panel link', function () {
    $notification = new AlertNotification($this->alert);
    $html = mailHtml($notification);

    expect(mailSubject($notification))->toBe('[CRITICAL] Shop <b>&</b> · production — Pending jobs')
        ->and($html)->toContain('4,312 jobs pending')
        ->and($html)->toContain('Detected at 2026-09-17 14:02 (Europe/Rome)')
        ->and($html)->toContain('queue-01, queue-02')
        ->and($html)->toContain('queue.pending &gt; 2,000 job')
        ->and($html)->toContain('4,312 job')
        ->and($html)->toContain('Shop &lt;b&gt;&amp;&lt;/b&gt; · production')
        ->and($html)->not->toContain('<b>&</b>')
        ->and($html)->toContain('href="'.url('/acme/environments/'.$this->environment->slug).'"')
        ->and($html)->toContain('Open the panel')
        ->and($html)->toContain('#e0685e')
        ->and($html)->not->toContain('Still open');
});

test('a repeated email says it keeps repeating', function () {
    expect(mailHtml(new AlertNotification($this->alert, repeated: true)))->toContain('Still open: this email repeats');
});

test('the email follows the organization time zone and the recipient language', function () {
    NotificationSetting::factory()->for($this->team)->create(['timezone' => 'America/New_York']);
    $notification = new AlertNotification($this->alert);

    expect(mailHtml($notification))->toContain('Detected at 2026-09-17 08:02 (America/New_York)')
        ->and(mailSubject($notification, 'it'))->toStartWith('[CRITICO] Shop')
        ->and(mailHtml($notification, 'it'))->toContain('Ambiente')
        ->and(mailHtml($notification, 'it'))->toContain('4.312');
});

test('a warning email is tagged as a warning in its colour', function () {
    $this->alert->update(['severity' => AlertSeverity::Warning]);
    $notification = new AlertNotification($this->alert);

    expect(mailSubject($notification))->toStartWith('[WARNING] ')
        ->and(mailHtml($notification))->toContain('#e3b857');
});

test('every rule has its own headline and detail', function (AlertRuleMetric $metric, float $value, array $detail, string $headline, ?string $extra) {
    $this->alert->update(['metric' => $metric, 'value' => $value, 'detail' => $detail, 'unit' => $metric->unit()]);

    $html = mailHtml(new AlertNotification($this->alert));

    expect($html)->toContain($headline);

    if ($extra !== null) {
        expect($html)->toContain($extra);
    }
})->with([
    'inactive' => [AlertRuleMetric::HorizonMasterInactive, 14, [], 'Horizon inactive for 14 min', null],
    'unreachable' => [AlertRuleMetric::EndpointUnreachable, 3, [], 'Endpoint unreachable for 3 min', null],
    'paused' => [AlertRuleMetric::HorizonPaused, 20, [], 'Horizon paused for 20 min', null],
    'max wait' => [AlertRuleMetric::QueueMaxWait, 95, ['waitSeconds' => 95], 'Oldest job waiting 95 s', null],
    'runtime' => [AlertRuleMetric::JobRuntime, 300, ['job' => 'App\Jobs\BuildReport', 'queue' => 'reports', 'seconds' => 300], 'A job has been running for 300 s', 'App\Jobs\BuildReport on queue reports'],
    'failed' => [AlertRuleMetric::JobsFailedPerHour, 31, ['failed' => 31], '31 jobs failed in the last hour', null],
    'workers' => [AlertRuleMetric::WorkersMissing, 5, ['queues' => ['default', 'emails']], '5 queues with waiting jobs and no worker', 'Queues: default, emails'],
]);

test('an alert of a deleted environment has no panel link and no nodes', function () {
    $alert = Alert::factory()->critical()->create([
        'environment_id' => null,
        'team_id' => $this->team->id,
        'application_name' => 'Shop',
        'environment_name' => 'gone',
        'environment_color' => EnvironmentColor::Staging,
    ]);

    $html = mailHtml(new AlertNotification($alert));

    expect($html)->not->toContain('Open the panel')
        ->and($html)->toContain('—');
});

test('a resolution email says how long the alert lasted', function () {
    $this->alert->update(['resolved_at' => CarbonImmutable::parse('2026-09-17 12:27:00', 'UTC')]);
    $notification = new ResolvedNotification($this->alert);
    $html = mailHtml($notification);

    expect(mailSubject($notification))->toBe('[RESOLVED] Shop <b>&</b> · production — Pending jobs')
        ->and($html)->toContain('Back within the threshold: Pending jobs')
        ->and($html)->toContain('Resolved at 2026-09-17 14:27 (Europe/Rome), open for 25 min')
        ->and($html)->toContain('#6fbf99');
});

test('the digest lists open and resolved warnings and links the wall', function () {
    $staging = Environment::factory()->for($this->application)->staging()->create();
    $alerts = collect([
        Alert::factory()->for($this->environment)->warning()->create(['metric' => AlertRuleMetric::QueueMaxWait, 'value' => 75, 'unit' => 's']),
        Alert::factory()->for($staging)->warning()->resolved()->create(['metric' => AlertRuleMetric::QueuePending, 'value' => 2100, 'unit' => 'job']),
        Alert::factory()->for($staging)->warning()->create(['metric' => AlertRuleMetric::JobsFailedPerHour, 'value' => 22, 'unit' => 'job']),
    ]);
    $notification = new WarningDigestNotification($this->team, $alerts);
    $html = mailHtml($notification);

    expect(mailSubject($notification))->toBe('[DIGEST] Acme — warnings')
        ->and($notification->environmentCount())->toBe(2)
        ->and($html)->toContain('Warnings: 3 · Environments: 2')
        ->and($html)->toContain('Max wait · 75 s')
        ->and($html)->toContain('Pending jobs · 2,100 job')
        ->and(substr_count($html, 'Still open'))->toBe(2)
        ->and($html)->toContain('Resolved')
        ->and($html)->toContain('href="'.url('/acme/wall').'"');
});

test('the test email names the organization', function () {
    $notification = new TestNotification($this->team);

    expect(mailSubject($notification))->toBe('[TEST] Acme — test notification')
        ->and(mailHtml($notification))->toContain('Alerts of Acme will reach this address.');
});

test('no email loads a remote resource', function (string $kind) {
    $html = mailHtml(match ($kind) {
        'alert' => new AlertNotification($this->alert),
        'resolved' => new ResolvedNotification($this->alert),
        'digest' => new WarningDigestNotification($this->team, collect([$this->alert])),
        'test' => new TestNotification($this->team),
    });

    expect($html)->not->toContain('<img')
        ->not->toContain('<link')
        ->not->toContain('@import')
        ->not->toContain('url(')
        ->not->toMatch('/src\s*=/i')
        ->and($html)->toContain('border-radius: 4px 0 0 4px')
        ->and($html)->toContain('background-color: #423a6a');
})->with(['alert', 'resolved', 'digest', 'test']);

test('the preview renders the opening email in the requested language', function () {
    $preview = app(EmailPreview::class);

    expect($preview->render($this->alert, 'en'))->toContain('4,312 jobs pending')
        ->and($preview->render($this->alert, 'it'))->toContain('Ambiente')
        ->and($preview->subject($this->alert, 'it'))->toStartWith('[CRITICO] ')
        ->and(app()->getLocale())->toBe('en');
});
