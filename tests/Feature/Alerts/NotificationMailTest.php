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
use App\Notifications\Alerts\AlertMail;
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
        ->and($html)->toContain('Detected on 2026-09-17 14:02 (Europe/Rome)')
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

    expect(mailHtml($notification))->toContain('Detected on 2026-09-17 08:02 (America/New_York)')
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

test('a state rule headline reads its duration in minutes, hours and days', function (AlertRuleMetric $metric, float $minutes, string $english, string $italian) {
    $this->alert->update(['metric' => $metric, 'value' => $minutes, 'detail' => [], 'unit' => $metric->unit()]);
    $notification = new AlertNotification($this->alert);

    expect(mailHtml($notification))->toContain($english)
        ->and(mailHtml($notification, 'it'))->toContain($italian)
        ->and(app(EmailPreview::class)->render($this->alert, 'en'))->toContain($english);
})->with([
    'minutes' => [AlertRuleMetric::HorizonMasterInactive, 59, 'Horizon inactive for 59 min', 'Horizon inattivo da 59 min'],
    'whole hours' => [AlertRuleMetric::HorizonPaused, 120, 'Horizon paused for 2 h<', 'Horizon in pausa da 2 h<'],
    'hours and minutes' => [AlertRuleMetric::HorizonMasterInactive, 1437, 'Horizon inactive for 23 h 57 min', 'Horizon inattivo da 23 h 57 min'],
    'whole days' => [AlertRuleMetric::EndpointUnreachable, 2880, 'Endpoint unreachable for 2 d<', 'Endpoint non raggiungibile da 2 g<'],
    'days and hours' => [AlertRuleMetric::EndpointUnreachable, 3000, 'Endpoint unreachable for 2 d 2 h', 'Endpoint non raggiungibile da 2 g 2 h'],
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
        ->and($html)->toContain('Resolved on 2026-09-17 14:27 (Europe/Rome), open for 25 min')
        ->and($html)->toContain('#6fbf99');
});

test('a long resolution says its duration in days and hours, in the language of the mail', function () {
    $this->alert->update(['resolved_at' => CarbonImmutable::parse('2026-09-18 15:32:00', 'UTC')]);
    $notification = new ResolvedNotification($this->alert);

    expect(mailHtml($notification))->toContain('Resolved on 2026-09-18 17:32 (Europe/Rome), open for 1 d 3 h')
        ->and(mailHtml($notification, 'it'))->toContain('Risolto il 2026-09-18 17:32 (Europe/Rome), aperto per 1 g 3 h')
        ->and(mailHtml(new AlertNotification($this->alert), 'it'))->toContain('Rilevato il 2026-09-17 14:02 (Europe/Rome)');
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
        ->and(app()->getLocale())->toBe('en');
});

const HOSTILE_MARKDOWN = "\n\n![p](https://evil.example.com/p.png) [x](https://evil.example.com)\n\n";

function expectNoInjectedMarkup(string $html): void
{
    expect($html)->not->toContain('<img')
        ->not->toMatch('/href\s*=\s*["\']?https?:\/\/evil\.example\.com/i')
        ->not->toContain('src="https://evil');
}

test('horizon names cannot inject markdown into an alert email or its preview', function () {
    EnvironmentState::query()->where('environment_id', $this->environment->id)->update(['nodes' => json_encode([
        ['hostname' => 'queue-01'.HOSTILE_MARKDOWN, 'status' => 'running', 'workers' => 1, 'supervisors' => 1, 'queues' => 1],
    ])]);
    $this->alert->update([
        'metric' => AlertRuleMetric::JobRuntime,
        'unit' => 's',
        'value' => 300,
        'detail' => ['job' => 'App\Jobs\BuildReport'.HOSTILE_MARKDOWN, 'queue' => 'reports'.HOSTILE_MARKDOWN, 'seconds' => 300],
    ]);
    $alert = $this->alert->fresh();

    foreach ([mailHtml(new AlertNotification($alert)), app(EmailPreview::class)->render($alert, 'en')] as $html) {
        expectNoInjectedMarkup($html);

        expect($html)->toContain('App\Jobs\BuildReport ![p](https://evil.example.com/p.png) [x](https://evil.example.com) on queue reports ![p](https://evil.example.com/p.png) [x](https://evil.example.com)')
            ->and($html)->toContain('queue-01 ![p](https://evil.example.com/p.png) [x](https://evil.example.com)');
    }
});

test('the queues without workers cannot inject markdown either', function () {
    $this->alert->update([
        'metric' => AlertRuleMetric::WorkersMissing,
        'detail' => ['queues' => ['default'.HOSTILE_MARKDOWN, 'emails']],
    ]);

    $html = mailHtml(new AlertNotification($this->alert->fresh()));

    expectNoInjectedMarkup($html);
    expect($html)->toContain('Queues: default ![p](https://evil.example.com/p.png) [x](https://evil.example.com), emails');
});

test('names typed in the panel cannot inject markdown into any alert email', function () {
    $this->team->update(['name' => 'Acme'.HOSTILE_MARKDOWN]);
    $this->alert->update(['application_name' => 'Shop'.HOSTILE_MARKDOWN, 'environment_name' => 'production'.HOSTILE_MARKDOWN]);
    $alert = $this->alert->fresh();
    $this->alert->update(['resolved_at' => now()]);
    $resolved = $this->alert->fresh();

    foreach ([
        new AlertNotification($alert),
        new ResolvedNotification($resolved),
        new WarningDigestNotification($this->team->fresh(), collect([$alert])),
        new TestNotification($this->team->fresh()),
    ] as $notification) {
        expectNoInjectedMarkup(mailHtml($notification));
        expect(mailSubject($notification))->not->toContain("\n");
    }

    $where = 'Shop ![p](https://evil.example.com/p.png) [x](https://evil.example.com) · production ![p](https://evil.example.com/p.png) [x](https://evil.example.com)';

    expect(mailHtml(new AlertNotification($alert)))->toContain($where)
        ->and(mailHtml(new WarningDigestNotification($this->team->fresh(), collect([$alert]))))->toContain($where)
        ->and(mailHtml(new TestNotification($this->team->fresh())))->toContain('Alerts of Acme ![p](https://evil.example.com/p.png) [x](https://evil.example.com) will reach this address.');

    expect(mailSubject(new AlertNotification($alert)))->toBe('[CRITICAL] Shop ![p](https://evil.example.com/p.png) [x](https://evil.example.com) · production ![p](https://evil.example.com/p.png) [x](https://evil.example.com) — Pending jobs');
});

test('markdown in a mail value stays text even without the sanitiser', function () {
    $html = (string) AlertMail::message()->markdown('mail.alerts.test', [
        'color' => AlertMail::RESOLVED_COLOR,
        'headline' => 'Test'.HOSTILE_MARKDOWN,
        'body' => 'Body'.HOSTILE_MARKDOWN,
        'url' => 'https://panel.example.com/acme/wall',
        'action' => 'Open the panel',
    ])->render();

    expectNoInjectedMarkup($html);
});

test('a value placed in a mail is one trimmed line capped at the configured length', function () {
    config(['horizon-watch.notifications.mail_value_length' => 10]);

    expect(AlertMail::plain("  a\r\n\tb\u{0000}c\u{202E}d\u{200B}e  "))->toBe('a b c d e')
        ->and(AlertMail::plain('0123456789'))->toBe('0123456789')
        ->and(AlertMail::plain('0123456789ABC'))->toBe('012345678…')
        ->and(AlertMail::plain("caf\xC3"))->toBe('caf?')
        ->and(AlertMail::plain("\n\n"))->toBe('');
});
