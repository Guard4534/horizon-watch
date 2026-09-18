<script setup lang="ts">
import { formatCount, formatWait } from '@/lib/monitoring';

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
        $t(':count jobs pending', {
            count: formatCount(alert.value ?? alert.pending),
        })
    }}</template>
    <template v-else-if="alert.metric === 'queue.max_wait'">{{
        $t('oldest job waiting :wait', {
            wait: formatWait(alert.value ?? alert.maxWaitSeconds),
        })
    }}</template>
    <template v-else-if="alert.metric === 'job.runtime' && alert.longestJob"
        ><span class="[overflow-wrap:anywhere]">{{
            $t(':job on queue :queue', {
                job: alert.longestJob,
                queue: alert.longestJobQueue ?? '—',
            })
        }}</span
        ><template v-if="alert.value !== null">
            · {{ formatWait(alert.value) }}</template
        ></template
    >
    <template v-else-if="alert.metric === 'job.runtime'">{{
        $t('a job has been running longer than the threshold')
    }}</template>
    <template
        v-else-if="
            alert.metric === 'jobs.failed_per_hour' && alert.value !== null
        "
        >{{
            $t(':count jobs failed in the last hour', {
                count: formatCount(alert.value),
            })
        }}</template
    >
    <template v-else-if="alert.metric === 'jobs.failed_per_hour'">{{
        $t('more jobs failed in the last hour than the threshold')
    }}</template>
    <template
        v-else-if="
            alert.metric === 'workers.missing' &&
            alert.queuesWithoutWorkers.length
        "
        >{{
            $t('Queues: :queues', {
                queues: alert.queuesWithoutWorkers.join(', '),
            })
        }}</template
    >
    <template v-else-if="alert.metric === 'workers.missing'">{{
        $tChoice(
            ':count queue or more with waiting jobs and no worker|:count queues or more with waiting jobs and no worker',
            alert.threshold,
        )
    }}</template>
    <template v-else>{{ $t('above the threshold') }}</template>
</template>
