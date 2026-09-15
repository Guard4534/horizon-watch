<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhPlugsConnected } from '@phosphor-icons/vue';
import { computed } from 'vue';
import ConnectionCard from '@/components/monitoring/environment/ConnectionCard.vue';
import EffectiveRules from '@/components/monitoring/environment/EffectiveRules.vue';
import FailedJobTable from '@/components/monitoring/environment/FailedJobTable.vue';
import IncidentBanner from '@/components/monitoring/environment/IncidentBanner.vue';
import LongRunningJobs from '@/components/monitoring/environment/LongRunningJobs.vue';
import NodeCard from '@/components/monitoring/environment/NodeCard.vue';
import QueueTable from '@/components/monitoring/environment/QueueTable.vue';
import TrendChart from '@/components/monitoring/environment/TrendChart.vue';
import MetricTile from '@/components/nocturne/MetricTile.vue';
import StatusPill from '@/components/nocturne/StatusPill.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { envColor, formatCount, formatWait, isDown, statusColor, statusLabel, waitColor } from '@/lib/monitoring';
import { index as applicationsIndex, show as showApplication } from '@/routes/applications';

defineOptions({
    layout: { title: 'Environment detail', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.EnvironmentDetailPageData;
}>();

usePoll(15000, { only: ['page', 'openAlertCount'] });

const slug = useTeamSlug();
const environment = computed(() => page.environment);
const down = computed(() => isDown(environment.value.status));
const threshold = (metric: App.Enums.AlertRuleMetric) => page.rules.find((rule) => rule.metric === metric)?.threshold ?? 0;

type Tile = { label: string; value: string; color: string; note: string; params: Record<string, string> };

// Notes are English keys with placeholders, translated in the template.
const tiles = computed<Tile[]>(() => [
    { label: 'Master', value: statusLabel(environment.value.status), color: statusColor(environment.value.status), note: down.value ? 'last heartbeat 14 min ago' : 'heartbeat healthy', params: {} as Record<string, string> },
    { label: 'Pending', value: formatCount(environment.value.pending), color: environment.value.pending > threshold('queue.pending') ? 'var(--st-down)' : 'var(--nc-text)', note: ':count queue', params: { count: String(page.queues.length) } },
    { label: 'Max wait', value: formatWait(environment.value.maxWaitSeconds), color: waitColor(environment.value.maxWaitSeconds), note: 'threshold :value', params: { value: `${threshold('queue.max_wait')}s` } },
    { label: 'Workers', value: String(environment.value.workers), color: environment.value.workers ? 'var(--nc-text)' : 'var(--st-down)', note: 'active processes', params: {} as Record<string, string> },
    { label: 'Throughput', value: String(environment.value.jobsPerMinute), color: 'var(--nc-text)', note: 'jobs/min', params: {} as Record<string, string> },
    { label: 'Failed 24h', value: String(environment.value.failedLast24Hours), color: environment.value.failedLast24Hours > threshold('jobs.failed_per_hour') ? 'var(--st-warn)' : 'var(--nc-text)', note: 'threshold :value', params: { value: String(threshold('jobs.failed_per_hour')) } },
    { label: 'Redis', value: `${environment.value.redisMemoryGb.toFixed(1)} GB`, color: 'var(--nc-text)', note: 'memory used', params: {} as Record<string, string> },
]);
</script>

<template>
    <Head :title="`${environment.applicationName} · ${environment.name}`" />

    <div class="flex flex-col" style="padding: var(--nc-space-6); gap: var(--nc-space-4)">
        <div class="flex flex-wrap items-center" style="gap: var(--nc-space-3)">
            <span class="h-[38px] w-1 flex-none rounded-[2px]" :style="{ background: envColor(environment.color) }" />
            <div class="min-w-0">
                <div style="font-size: 11px; color: var(--nc-neutral-500)">
                    <Link :href="applicationsIndex(slug)">{{ $t('Applications') }}</Link>
                    /
                    <Link :href="showApplication({ current_team: slug, application: environment.applicationId })">{{ environment.applicationName }}</Link>
                </div>
                <div style="font-size: 24px; line-height: 1.15">{{ environment.name }}</div>
            </div>
            <StatusPill :status="environment.status" />
            <div class="ml-auto flex flex-wrap" style="gap: var(--nc-space-2)">
                <button type="button" class="nc-btn nc-btn-secondary" disabled :title="$t('Available soon')">
                    <PhPlugsConnected :size="14" />{{ $t('Test connection') }}
                </button>
                <a :href="environment.horizonUrl" target="_blank" rel="noopener noreferrer" class="nc-btn nc-btn-primary">
                    <PhArrowSquareOut :size="14" />{{ $t('Open Horizon') }}
                </a>
            </div>
        </div>

        <div style="font-size: 11px; color: var(--nc-neutral-500); letter-spacing: 0.01em">
            {{ environment.horizonUrl }}<template v-if="environment.basicAuthUser"> · basic auth: {{ environment.basicAuthUser }}</template>
            · {{ $t('last response') }} {{ environment.latencyMs }} ms
        </div>

        <IncidentBanner v-if="environment.status !== 'active'" :environment="environment" :alert="page.openAlert" />

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: var(--nc-space-3)">
            <MetricTile v-for="tile in tiles" :key="tile.label" :label="tile.label" :value="tile.value" :color="tile.color" :note="$t(tile.note, tile.params)" />
        </div>

        <section class="nc-card">
            <div class="mb-[var(--nc-space-3)] flex flex-wrap items-baseline gap-2">
                <span style="font-size: 14px">{{ $t('Nodes (master supervisors)') }}</span>
                <span style="font-size: 11px; color: var(--nc-neutral-500)">{{ $t('one master per machine') }} · /horizon/api/masters</span>
            </div>
            <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(224px, 1fr)); gap: var(--nc-space-3)">
                <NodeCard v-for="node in page.nodes" :key="node.hostname" :node="node" />
            </div>
        </section>

        <div class="mt-[var(--nc-space-2)] grid items-start" style="grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: var(--nc-space-6)">
            <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
                <TrendChart :throughput="page.throughput" :max-wait="page.maxWait" :range="page.range" />
                <QueueTable :queues="page.queues" />
                <FailedJobTable :jobs="page.failedJobs" />
            </div>
            <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
                <LongRunningJobs :jobs="page.longRunningJobs" :threshold-seconds="threshold('job.runtime')" />
                <EffectiveRules :rules="page.rules" :override-count="page.overrideCount" :environment-name="environment.name" />
                <ConnectionCard :environment="environment" />
            </div>
        </div>
    </div>
</template>
