<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;

test('owners have every permission', function () {
    expect(TeamRole::Owner->permissions())->toEqualCanonicalizing(TeamPermission::cases());
});

test('admins have every permission except deleting the team', function () {
    expect(TeamRole::Admin->permissions())->toEqualCanonicalizing([
        TeamPermission::UpdateTeam,
        TeamPermission::UpdateMember,
        TeamPermission::RemoveMember,
        TeamPermission::CreateInvitation,
        TeamPermission::CancelInvitation,
        TeamPermission::ManageApplications,
        TeamPermission::ManageCredentials,
        TeamPermission::ManageAlertRules,
        TeamPermission::MuteAlert,
        TeamPermission::HandleAnomaly,
        TeamPermission::TestConnection,
    ]);
});

test('members can only act on alerts', function () {
    expect(TeamRole::Member->permissions())->toEqualCanonicalizing([
        TeamPermission::MuteAlert,
        TeamPermission::HandleAnomaly,
        TeamPermission::TestConnection,
    ]);
});

test('viewers have no permissions', function () {
    expect(TeamRole::Viewer->permissions())->toEqualCanonicalizing([]);
});

test('role levels order owner above admin above member above viewer', function () {
    expect(TeamRole::Owner->isAtLeast(TeamRole::Owner))->toBeTrue()
        ->and(TeamRole::Owner->isAtLeast(TeamRole::Admin))->toBeTrue()
        ->and(TeamRole::Owner->isAtLeast(TeamRole::Member))->toBeTrue()
        ->and(TeamRole::Owner->isAtLeast(TeamRole::Viewer))->toBeTrue()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Owner))->toBeFalse()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Admin))->toBeTrue()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Member))->toBeTrue()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Viewer))->toBeTrue()
        ->and(TeamRole::Member->isAtLeast(TeamRole::Admin))->toBeFalse()
        ->and(TeamRole::Member->isAtLeast(TeamRole::Member))->toBeTrue()
        ->and(TeamRole::Member->isAtLeast(TeamRole::Viewer))->toBeTrue()
        ->and(TeamRole::Viewer->isAtLeast(TeamRole::Member))->toBeFalse()
        ->and(TeamRole::Viewer->isAtLeast(TeamRole::Viewer))->toBeTrue();
});
