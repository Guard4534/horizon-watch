<?php

namespace App\Alerts;

use App\Models\Alert;
use App\Models\Membership;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class Recipients
{
    /**
     * @var array<int, list<array{email: string, locale: string, user: null, environmentIds: null, extra: true}>>
     */
    private array $extrasByTeam = [];

    public function __construct(
        private readonly VisibleEnvironments $visible,
        private readonly EffectiveRules $rules,
    ) {}

    /**
     * @param  list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null, extra: bool}>  $audience
     * @return list<array{email: string, locale: string, user: ?User}>
     */
    public function forAlertIn(Alert $alert, array $audience): array
    {
        /** @var Team|null $team */
        $team = $alert->team;

        if ($team === null || ! $this->emailed($alert)) {
            return [];
        }

        $environmentId = $alert->environment?->id;

        return $this->withoutScope(array_values(array_filter($audience, fn (array $recipient) => $environmentId === null
            ? $recipient['extra']
            : $recipient['environmentIds'] === null || in_array($environmentId, $recipient['environmentIds'], true))));
    }

    /**
     * @return list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null, extra: bool}>
     */
    public function forDigest(Team $team): array
    {
        $extras = $this->extras($team);
        $extraEmails = array_flip(array_column($extras, 'email'));
        $memberships = $this->optedIn($team);
        $visible = $this->visible->idsForAllOf($team, $memberships);
        $members = [];

        foreach ($memberships as $membership) {
            $member = $this->member($membership->user);
            $extra = isset($extraEmails[$member['email']]);

            $members[] = [...$member, 'environmentIds' => $extra ? null : $visible[$membership->id] ?? null, 'extra' => $extra];
        }

        return $this->unique([...$members, ...$extras]);
    }

    /**
     * @return list<array{email: string, locale: string, user: ?User}>
     */
    public function forTest(Team $team, User $requestedBy): array
    {
        return $this->withoutScope($this->unique([$this->member($requestedBy), ...$this->extras($team)]));
    }

    public function extraAddress(Team $team, string $key): ?string
    {
        foreach ($this->extras($team) as $extra) {
            if (hash_equals(self::addressKey($extra['email']), $key)) {
                return $extra['email'];
            }
        }

        return null;
    }

    public static function addressKey(string $email): string
    {
        return hash_hmac('sha256', Str::lower($email), config()->string('app.key'));
    }

    /**
     * @return Collection<int, Membership>
     */
    private function optedIn(Team $team): Collection
    {
        return Membership::query()
            ->where('team_id', $team->id)
            ->whereHas('user', fn ($users) => $users->where('alert_emails', true))
            ->with('user')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{email: string, locale: string, user: ?User, environmentIds: null}
     */
    private function member(User $user): array
    {
        return [
            'email' => Str::lower($user->email),
            'locale' => $user->preferredLocale() ?? $this->defaultLocale(),
            'user' => $user,
            'environmentIds' => null,
        ];
    }

    private function emailed(Alert $alert): bool
    {
        $environment = $alert->environment;
        $rules = $environment !== null
            ? $this->rules->forEnvironment($environment)
            : $this->rules->forScope($alert->team, $alert->environment_name);

        return $rules->for($alert->metric)->notifyByEmail;
    }

    /**
     * @return list<array{email: string, locale: string, user: null, environmentIds: null, extra: true}>
     */
    private function extras(Team $team): array
    {
        return $this->extrasByTeam[$team->id] ??= $this->readExtras($team);
    }

    /**
     * @return list<array{email: string, locale: string, user: null, environmentIds: null, extra: true}>
     */
    private function readExtras(Team $team): array
    {
        $setting = NotificationSetting::query()->find($team->id);
        $extras = [];
        $skipped = 0;

        foreach ($setting === null ? [] : $setting->recipients as $address) {
            $email = Str::lower(trim((string) $address));

            if (! Validator::make(['address' => $email], ['address' => 'email:rfc'])->passes()) {
                $skipped++;

                continue;
            }

            $extras[] = ['email' => $email, 'locale' => $this->defaultLocale(), 'user' => null, 'environmentIds' => null, 'extra' => true];
        }

        if ($skipped > 0) {
            Log::warning('Invalid extra alert addresses skipped.', ['team' => $team->id, 'count' => $skipped]);
        }

        return $extras;
    }

    /**
     * @template T of array{email: string}
     *
     * @param  list<T>  $recipients
     * @return list<T>
     */
    private function unique(array $recipients): array
    {
        $unique = [];

        foreach ($recipients as $recipient) {
            $unique[$recipient['email']] ??= $recipient;
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{email: string, locale: string, user: ?User}>  $recipients
     * @return list<array{email: string, locale: string, user: ?User}>
     */
    private function withoutScope(array $recipients): array
    {
        return array_map(fn (array $recipient) => [
            'email' => $recipient['email'],
            'locale' => $recipient['locale'],
            'user' => $recipient['user'],
        ], $recipients);
    }

    private function defaultLocale(): string
    {
        return config()->string('app.locale');
    }
}
