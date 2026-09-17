<script setup lang="ts">
import { formatCount, formatWait } from '@/lib/monitoring';

// One literal $t() per metric, so TranslationsTest sees every sentence. Each
// says only what the stored reading can back: the alert carries the
// environment's totals, not the value that crossed the rule.
defineProps<{
    alert: App.Data.Monitoring.AlertData;
}>();
</script>

<template>
    <template v-if="alert.metric === 'horizon.master_inactive'">{{
        $t('Horizon reports no running master supervisor')
    }}</template>
    <template v-else-if="alert.metric === 'endpoint.unreachable'">{{
        $t('The last reading of Horizon failed')
    }}</template>
    <template v-else-if="alert.metric === 'horizon.paused'">{{
        $t(':count jobs pending while paused', {
            count: formatCount(alert.pending),
        })
    }}</template>
    <template v-else-if="alert.metric === 'queue.pending'">{{
        $t(':count jobs pending', { count: formatCount(alert.pending) })
    }}</template>
    <template v-else-if="alert.metric === 'queue.max_wait'">{{
        $t('oldest job waiting :wait', {
            wait: formatWait(alert.maxWaitSeconds),
        })
    }}</template>
    <template v-else-if="alert.metric === 'job.runtime'">{{
        $t('a job has been running longer than the threshold')
    }}</template>
    <template v-else-if="alert.metric === 'jobs.failed_per_hour'">{{
        $t('more jobs failed in the last hour than the threshold')
    }}</template>
    <template v-else-if="alert.metric === 'workers.missing'">{{
        $tChoice(
            ':count queue or more with waiting jobs and no worker|:count queues or more with waiting jobs and no worker',
            alert.threshold,
        )
    }}</template>
    <template v-else>{{ $t('above the threshold') }}</template>
</template>
