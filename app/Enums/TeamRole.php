<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner · admin'),
            self::Admin => __('Admin'),
            self::Member => __('Member'),
            self::Viewer => __('Viewer'),
        };
    }

    /**
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => array_values(array_filter(
                TeamPermission::cases(),
                fn (TeamPermission $permission) => $permission !== TeamPermission::DeleteTeam,
            )),
            self::Member => [
                TeamPermission::MuteAlert,
                TeamPermission::HandleAnomaly,
                TeamPermission::TestConnection,
            ],
            self::Viewer => [],
        };
    }

    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    public function level(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Member => 2,
            self::Viewer => 1,
        };
    }

    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }
}
