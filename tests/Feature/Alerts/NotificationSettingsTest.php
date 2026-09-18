<?php

use App\Actions\Alerts\UpdateNotificationSettings;
use App\Data\Alerts\NotificationSettingsInputData;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Externals\Horizon\Dns\Resolver;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();
    Environment::factory()->for(Application::factory()->for($this->team))->production()->create();

    $this->memberOf = function (TeamRole $role): User {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => MemberVisibility::All->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->admin = ($this->memberOf)(TeamRole::Admin);

    $this->payload = fn (array $overrides = []): array => array_merge([
        'recipients' => ['ops@example.com', 'OnCall@Example.com', 'ops@example.com'],
        'webhookUrl' => 'https://hooks.example.com/horizon/t0ken-in-path',
        'quietFrom' => '23:00',
        'quietTo' => '07:30',
        'timezone' => 'America/New_York',
        'repeatMinutes' => 60,
    ], $overrides);

    $this->save = fn (array $overrides = []) => $this->put(
        route('alert-settings.update', ['current_team' => $this->team->slug]),
        ($this->payload)($overrides),
    );

    $this->page = fn () => $this->get(route('alert-rules.index', ['current_team' => $this->team->slug]));

    $this->logged = [];
    Log::listen(function ($message) {
        $this->logged[] = json_encode([$message->message, $message->context]);
    });
});

test('without a saved row the page shows the defaults', function () {
    $this->actingAs($this->admin);

    ($this->page)()->assertInertia(fn (Assert $page) => $page
        ->where('page.canManage', true)
        ->where('page.newWebhookSecret', null)
        ->where('page.notifications.recipients', [])
        ->where('page.notifications.webhookUrl', null)
        ->where('page.notifications.webhookSecretSet', false)
        ->where('page.notifications.quietFrom', null)
        ->where('page.notifications.quietTo', null)
        ->where('page.notifications.timezone', 'Europe/Rome')
        ->where('page.notifications.repeatMinutes', 30)
        ->missing('page.notifications.timezones')
        ->where('page.timezones', DateTimeZone::listIdentifiers())
        ->where('page.notificationSummary', [
            'recipientCount' => 0,
            'webhookConfigured' => false,
            'quietFrom' => null,
            'quietTo' => null,
            'timezone' => 'Europe/Rome',
            'repeatMinutes' => 30,
        ]));
});

test('only the alert settings page carries the list of time zones, the polled alerts page does not', function () {
    $this->actingAs($this->admin);

    $alerts = $this->get(route('alerts.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.notifications.timezone', 'Europe/Rome')
            ->missing('page.notifications.timezones')
            ->missing('page.timezones'));

    expect($alerts->getContent())->not->toContain('America\\/New_York');
});

test('an admin saves the settings, the recipients deduplicated in lowercase and the quiet hours as H:i', function () {
    $this->actingAs($this->admin);

    ($this->save)()
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Notification settings saved.');

    $settings = NotificationSetting::query()->sole();
    expect($settings->team_id)->toBe($this->team->id)
        ->and($settings->recipients)->toBe(['ops@example.com', 'oncall@example.com'])
        ->and($settings->webhook_url)->toBe('https://hooks.example.com/horizon/t0ken-in-path')
        ->and($settings->timezone)->toBe('America/New_York')
        ->and($settings->repeat_minutes)->toBe(60)
        ->and($settings->getRawOriginal('quiet_from'))->toBe('23:00:00');

    ($this->page)()->assertInertia(fn (Assert $page) => $page
        ->where('page.notifications.recipients', ['ops@example.com', 'oncall@example.com'])
        ->where('page.notifications.webhookSecretSet', true)
        ->where('page.notifications.quietFrom', '23:00')
        ->where('page.notifications.quietTo', '07:30')
        ->where('page.notifications.timezone', 'America/New_York')
        ->where('page.notifications.repeatMinutes', 60)
        ->where('page.notificationSummary.recipientCount', 2)
        ->where('page.notificationSummary.timezone', 'America/New_York'));
});

test('a second save updates the same row, and never repeating is a null cadence', function () {
    $this->actingAs($this->admin);

    ($this->save)();
    ($this->save)(['recipients' => [], 'quietFrom' => null, 'quietTo' => null, 'repeatMinutes' => null])
        ->assertSessionHasNoErrors();

    $settings = NotificationSetting::query()->sole();
    expect($settings->recipients)->toBe([])
        ->and($settings->quiet_from)->toBeNull()
        ->and($settings->quiet_to)->toBeNull()
        ->and($settings->repeat_minutes)->toBeNull();

    ($this->page)()->assertInertia(fn (Assert $page) => $page
        ->where('page.notifications.repeatMinutes', null)
        ->where('page.notificationSummary.repeatMinutes', null));
});

test('the first webhook address generates a secret, shown once, encrypted at rest', function () {
    $this->actingAs($this->admin);

    ($this->save)();

    $settings = NotificationSetting::query()->sole();
    $secret = $settings->webhook_secret;

    expect($secret)->toBeString()->toHaveLength(40)
        ->and($settings->getRawOriginal('webhook_secret'))->not->toContain($secret);

    expect(json_encode(session()->all()))->not->toContain($secret);

    $first = ($this->page)();
    $first->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', $secret));

    $second = ($this->page)();
    $second->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', null));

    expect($second->getContent())->not->toContain($secret)
        ->and(implode("\n", $this->logged))->not->toContain($secret);
});

test('the save response itself carries neither the secret nor the address', function () {
    $this->actingAs($this->admin);

    $response = ($this->save)();

    $secret = NotificationSetting::query()->sole()->webhook_secret;

    expect($response->getContent())->not->toContain($secret)
        ->and(json_encode(session()->all()))->not->toContain($secret);
});

test('saving again with an address keeps the secret and shows nothing new', function () {
    $this->actingAs($this->admin);

    ($this->save)();
    ($this->page)();
    $secret = NotificationSetting::query()->sole()->webhook_secret;

    ($this->save)(['webhookUrl' => 'https://hooks.example.net/other']);

    expect(NotificationSetting::query()->sole()->webhook_secret)->toBe($secret);
    ($this->page)()->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', null));
});

test('removing the address removes the secret', function (?string $removed) {
    $this->actingAs($this->admin);

    ($this->save)();
    ($this->page)();
    ($this->save)(['webhookUrl' => $removed])->assertSessionHasNoErrors();

    $settings = NotificationSetting::query()->sole();
    expect($settings->webhook_url)->toBeNull()
        ->and($settings->webhook_secret)->toBeNull()
        ->and($settings->getRawOriginal('webhook_secret'))->toBeNull();

    ($this->page)()->assertInertia(fn (Assert $page) => $page
        ->where('page.notifications.webhookSecretSet', false)
        ->where('page.notificationSummary.webhookConfigured', false)
        ->where('page.newWebhookSecret', null));
})->with([null, '', '   ']);

test('regenerating makes a new secret, shown once', function () {
    $this->actingAs($this->admin);

    ($this->save)();
    ($this->page)();
    $old = NotificationSetting::query()->sole()->webhook_secret;

    $this->post(route('alert-settings.regenerate-secret', ['current_team' => $this->team->slug]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'New webhook secret generated.');

    $new = NotificationSetting::query()->sole()->webhook_secret;
    expect($new)->not->toBe($old)->toHaveLength(40);

    ($this->page)()->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', $new));
    ($this->page)()->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', null));
});

test('regenerating without an address is refused', function (bool $withRow) {
    if ($withRow) {
        NotificationSetting::factory()->for($this->team)->create();
    }

    $this->actingAs($this->admin)
        ->post(route('alert-settings.regenerate-secret', ['current_team' => $this->team->slug]))
        ->assertSessionHasErrors(['webhookUrl' => 'Save a webhook address first.']);

    expect(NotificationSetting::query()->count())->toBe($withRow ? 1 : 0);
    ($this->page)()->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', null));
})->with(['no row' => false, 'a row without address' => true]);

test('a session value that is not a sealed secret is ignored', function () {
    $this->actingAs($this->admin)
        ->withSession(['alert-settings.new-webhook-secret.'.$this->team->id => 'plain-text'])
        ->get(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', null));
});

test('the settings are validated', function (array $overrides, string $error) {
    $this->actingAs($this->admin);

    ($this->save)($overrides)->assertSessionHasErrors($error);

    expect(NotificationSetting::query()->count())->toBe(0);
})->with([
    'a recipient that is not an email' => [['recipients' => ['ops@example.com', 'not-an-email']], 'recipients.1'],
    'too many recipients' => [['recipients' => array_map(fn (int $i) => "ops{$i}@example.com", range(1, 21))], 'recipients'],
    'recipients as a string' => [['recipients' => 'ops@example.com'], 'recipients'],
    'recipients missing' => [['recipients' => null], 'recipients'],
    'an address with credentials' => [['webhookUrl' => 'https://user:pass@hooks.example.com/horizon'], 'webhookUrl'],
    'an address with a query' => [['webhookUrl' => 'https://hooks.example.com/horizon?token=abc'], 'webhookUrl'],
    'an address with a fragment' => [['webhookUrl' => 'https://hooks.example.com/horizon#abc'], 'webhookUrl'],
    'a non-http address' => [['webhookUrl' => 'ftp://hooks.example.com/horizon'], 'webhookUrl'],
    'the metadata address' => [['webhookUrl' => 'http://169.254.169.254/latest'], 'webhookUrl'],
    'the metadata name' => [['webhookUrl' => 'http://metadata.google.internal/hook'], 'webhookUrl'],
    'a mapped metadata address' => [['webhookUrl' => 'http://[::ffff:169.254.169.254]/hook'], 'webhookUrl'],
    'an address too long' => [['webhookUrl' => 'https://hooks.example.com/'.str_repeat('a', 2030)], 'webhookUrl'],
    'quiet from without quiet to' => [['quietTo' => null], 'quietTo'],
    'quiet to without quiet from' => [['quietFrom' => null], 'quietFrom'],
    'quiet hours with seconds' => [['quietFrom' => '23:00:00'], 'quietFrom'],
    'quiet hours out of range' => [['quietTo' => '24:30'], 'quietTo'],
    'an unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'a timezone abbreviation' => [['timezone' => 'CEST'], 'timezone'],
    'a cadence outside the list' => [['repeatMinutes' => 45], 'repeatMinutes'],
    'a cadence of zero' => [['repeatMinutes' => 0], 'repeatMinutes'],
]);

test('the whole payload must be sent', function (string $missing) {
    $this->actingAs($this->admin);

    $payload = ($this->payload)();
    unset($payload[$missing]);

    $this->put(route('alert-settings.update', ['current_team' => $this->team->slug]), $payload)
        ->assertSessionHasErrors($missing);
})->with(['recipients', 'webhookUrl', 'quietFrom', 'quietTo', 'timezone', 'repeatMinutes']);

test('a private address is accepted unless private networks are blocked', function (bool $blocked) {
    config(['horizon-watch.block_private_networks' => $blocked]);

    $this->actingAs($this->admin);
    $response = ($this->save)(['webhookUrl' => 'http://10.0.0.5/hook']);

    $blocked
        ? $response->assertSessionHasErrors('webhookUrl')
        : $response->assertSessionHasNoErrors();
})->with([true, false]);

test('a host name is never resolved while validating', function () {
    $this->mock(Resolver::class)->shouldNotReceive('resolve');

    $this->actingAs($this->admin);

    ($this->save)(['webhookUrl' => 'https://unresolvable.invalid/hook'])->assertSessionHasNoErrors();
});

test('a refused save never flashes the address, the recipients or a secret', function () {
    $this->actingAs($this->admin);

    $response = $this->from(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->put(route('alert-settings.update', ['current_team' => $this->team->slug]), ($this->payload)([
            'webhookUrl' => 'https://user:hunter2-secret@hooks.example.com/horizon',
            'recipients' => ['private-person@example.com', 'broken'],
            'webhookSecret' => 'client-sent-secret',
            'timezone' => 'Nowhere/Land',
        ]));

    $response->assertSessionHasErrors(['webhookUrl', 'recipients.1', 'timezone']);

    $flashed = json_encode(session()->all(), JSON_UNESCAPED_SLASHES);
    expect($flashed)
        ->not->toContain('hunter2-secret')
        ->not->toContain('private-person@example.com')
        ->not->toContain('client-sent-secret')
        ->toContain('Nowhere/Land');

    $errors = json_encode(session('errors')->getMessages());
    expect($errors)->not->toContain('hunter2')->not->toContain('private-person');

    $page = ($this->page)();
    expect($page->getContent())->not->toContain('hunter2-secret')->not->toContain('private-person@example.com')
        ->and(implode("\n", $this->logged))->not->toContain('hunter2-secret');
});

test('only a role that manages alert rules may write the settings', function (TeamRole $role, bool $allowed) {
    NotificationSetting::factory()->for($this->team)->withWebhook()->create();
    $before = NotificationSetting::query()->sole()->webhook_secret;

    $this->actingAs(($this->memberOf)($role));

    $save = ($this->save)();
    $regenerate = $this->post(route('alert-settings.regenerate-secret', ['current_team' => $this->team->slug]));

    if ($allowed) {
        $save->assertRedirect();
        $regenerate->assertRedirect();
        expect(NotificationSetting::query()->sole()->webhook_secret)->not->toBe($before);
    } else {
        $save->assertForbidden();
        $regenerate->assertForbidden();
        expect(NotificationSetting::query()->sole()->webhook_secret)->toBe($before)
            ->and(NotificationSetting::query()->sole()->timezone)->toBe('Europe/Rome');
    }
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('members and viewers see only counts in the raw response, never targets or secrets', function (TeamRole $role) {
    $settings = NotificationSetting::factory()->for($this->team)->withWebhook('https://hooks.example.com/horizon/t0ken-in-path')->quiet()->create([
        'recipients' => ['ops@example.com', 'oncall@example.com'],
    ]);

    $user = ($this->memberOf)($role);
    $this->actingAs($user)
        ->withSession(['alert-settings.new-webhook-secret' => encrypt($settings->webhook_secret, false)]);

    $response = ($this->page)()
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.canManage', false)
            ->where('page.notifications', null)
            ->where('page.newWebhookSecret', null)
            ->where('page.notificationSummary.recipientCount', 2)
            ->where('page.notificationSummary.webhookConfigured', true)
            ->where('page.notificationSummary.quietFrom', '23:00')
            ->where('page.notificationSummary.timezone', 'Europe/Rome'));

    expect($response->getContent())
        ->not->toContain('hooks.example.com')
        ->not->toContain('t0ken-in-path')
        ->not->toContain('ops@example.com')
        ->not->toContain('oncall@example.com')
        ->not->toContain($settings->webhook_secret)
        ->not->toContain($settings->getRawOriginal('webhook_secret'))
        ->not->toContain('Pacific/Auckland');
})->with([TeamRole::Member, TeamRole::Viewer]);

test('the settings of another organization never leak into the page', function () {
    NotificationSetting::factory()->for(Team::factory())->withWebhook('https://hooks.example.org/elsewhere')->create([
        'recipients' => ['someone@example.org'],
    ]);

    $this->actingAs($this->admin);
    ($this->page)()->assertInertia(fn (Assert $page) => $page
        ->where('page.notifications.recipients', [])
        ->where('page.notifications.webhookUrl', null));
});

test('the one-time secret shows only on the rules page of the organization it belongs to', function () {
    $other = Team::factory()->create();
    Environment::factory()->for(Application::factory()->for($other))->production()->create();
    $other->members()->attach($this->admin, ['role' => TeamRole::Admin->value, 'visibility' => MemberVisibility::All->value]);

    $this->actingAs($this->admin);
    ($this->save)();
    $secret = NotificationSetting::query()->sole()->webhook_secret;

    $elsewhere = $this->get(route('alert-rules.index', ['current_team' => $other->slug]));

    $elsewhere->assertInertia(fn (Assert $page) => $page->where('page.newWebhookSecret', null));
    expect($elsewhere->getContent())->not->toContain($secret);
});

test('the page that shows the secret is encrypted in the browser history, and the next page clears that history', function () {
    $this->actingAs($this->admin);

    ($this->page)()->assertInertia(fn (Assert $page) => expect($page->toArray())->not->toHaveKeys(['encryptHistory', 'clearHistory']));

    ($this->save)();

    ($this->page)()->assertInertia(fn (Assert $page) => expect($page->toArray())
        ->toHaveKey('encryptHistory', true)
        ->not->toHaveKey('clearHistory')
        ->and($page->toArray()['props']['page']['newWebhookSecret'])->toBeString());

    $this->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => expect($page->toArray())->toHaveKey('clearHistory', true)->not->toHaveKey('encryptHistory'));

    ($this->page)()->assertInertia(fn (Assert $page) => expect($page->toArray())->not->toHaveKeys(['encryptHistory', 'clearHistory']));
});

test('saving the same settings twice keeps one row and the first secret', function () {
    $this->actingAs($this->admin);

    ($this->save)()->assertSessionHasNoErrors();
    $secret = NotificationSetting::query()->sole()->webhook_secret;
    ($this->save)()->assertSessionHasNoErrors();

    expect(NotificationSetting::query()->sole()->webhook_secret)->toBe($secret);
});

test('a first save that loses the race for the row updates it instead of failing', function (bool $competitorHasAddress) {
    $raced = false;

    DB::beforeExecuting(function (string $sql) use (&$raced, $competitorHasAddress) {
        if (! $raced && str_starts_with($sql, 'insert into "notification_settings"')) {
            $raced = true;
            NotificationSetting::factory()->for($this->team)->create([
                'webhook_url' => $competitorHasAddress ? 'https://hooks.example.org/first' : null,
                'webhook_secret' => $competitorHasAddress ? str_repeat('s', 40) : null,
            ]);
        }
    });

    $secret = app(UpdateNotificationSettings::class)->handle($this->team, NotificationSettingsInputData::from(($this->payload)()));

    $settings = NotificationSetting::query()->sole();

    expect($settings->webhook_url)->toBe('https://hooks.example.com/horizon/t0ken-in-path')
        ->and($settings->recipients)->toBe(['ops@example.com', 'oncall@example.com'])
        ->and($settings->webhook_secret)->toBe($competitorHasAddress ? str_repeat('s', 40) : $secret)
        ->and($secret)->toBe($competitorHasAddress ? null : $settings->webhook_secret);
})->with(['the competitor has no address' => false, 'the competitor already has an address' => true]);
