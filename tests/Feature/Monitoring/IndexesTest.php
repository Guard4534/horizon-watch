<?php

use Illuminate\Support\Facades\Schema;

test('the lookups behind cascades, sweeps and prunes are indexed', function (string $table, string $index) {
    expect(Schema::hasIndex($table, $index))->toBeTrue();
})->with([
    'notifications by alert' => ['alert_notifications', 'alert_notifications_alert_id_channel_target_index'],
    'orphan notifications' => ['alert_notifications', 'alert_notifications_orphan_sent_at_index'],
    'alerts by environment' => ['alerts', 'alerts_environment_id_index'],
    'resolved alerts' => ['alerts', 'alerts_resolved_at_index'],
    'pending digests' => ['alerts', 'alerts_digest_pending_index'],
    'alerts muted by a user' => ['alerts', 'alerts_muted_by_index'],
    'alerts handled by a user' => ['alerts', 'alerts_handled_by_index'],
    'grants by user' => ['environment_user', 'environment_user_user_id_index'],
    'alerts muted by a maintenance window' => ['alerts', 'alerts_maintenance_window_id_index'],
    'maintenance windows by application' => ['maintenance_windows', 'maintenance_windows_application_id_index'],
    'maintenance windows by environment' => ['maintenance_windows', 'maintenance_windows_environment_id_index'],
    'maintenance windows by creator' => ['maintenance_windows', 'maintenance_windows_created_by_index'],
    'ended maintenance windows' => ['maintenance_windows', 'maintenance_windows_ends_at_index'],
]);
