<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case ManageApplications = 'application:manage';
    case ManageCredentials = 'credential:manage';
    case ManageAlertRules = 'alert-rule:manage';
    case MuteAlert = 'alert:mute';
    case HandleAnomaly = 'anomaly:handle';
    case TestConnection = 'connection:test';
}
