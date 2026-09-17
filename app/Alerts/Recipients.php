<?php

namespace App\Alerts;

use App\Enums\MemberVisibility;
use App\Models\Alert;
use App\Models\Membership;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final readonly class Recipients
{
    public function __construct(
        private VisibleEnvironments $visible,
        private EffectiveRules $rules,
    ) {}

    /**
     * @return list<array{email: string, locale: string, user: ?User}>
     */
    public function forAlert(Alert $alert): array
    {
        /** @var Team|null $team */
        $team = $alert->team;

        if ($team === null) {
            return [];
        }

        $environment = $alert->environment;
        $rules = $environment !== null
            ? $this->rules->forEnvironment($environment)
            : $this->rules->forScope($team, $alert->environment_name);

        if (! $rules->for($alert->metric)->notifyByEmail) {
            return [];
        }

        $members = [];

        if ($environment !== null) {
            foreach ($this->optedIn($team) as $membership) {
                if ($this->visible->query($team, $membership->user)->whereKey($environment->id)->exists()) {
                    $members[] = $this->member($membership->user);
                }
            }
        }

        return $this->withoutScope($this->unique([...$members, ...$this->extras($team)]));
    }

    /**
     * @return list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null}>
     */
    public function forDigest(Team $team): array
    {
        $members = [];

        foreach ($this->optedIn($team) as $membership) {
            $environmentIds = null;

            if ($membership->visibility !== MemberVisibility::All) {
                $environmentIds = array_values(array_map(
                    intval(...),
                    $this->visible->query($team, $membership->user)->pluck('environments.id')->all(),
                ));
            }

            $members[] = [...$this->member($membership->user), 'environmentIds' => $environmentIds];
        }

        return $this->unique([...$members, ...$this->extras($team)]);
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
        return hash_hmac('sha256', Str::lower($email), (string) config('app.key'));
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

    /**
     * @return list<array{email: string, locale: string, user: null, environmentIds: null}>
     */
    private function extras(Team $team): array
    {
        $setting = NotificationSetting::query()->find($team->id);
        $extras = [];

        foreach ($setting === null ? [] : $setting->recipients as $address) {
            $email = Str::lower(trim((string) $address));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $extras[] = ['email' => $email, 'locale' => $this->defaultLocale(), 'user' => null, 'environmentIds' => null];
            }
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
     * @param  list<array{email: string, locale: string, user: ?User, environmentIds: null}>  $recipients
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
        return (string) config('app.locale');
    }
}
