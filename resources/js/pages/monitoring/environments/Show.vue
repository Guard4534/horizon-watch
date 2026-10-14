<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhShieldCheck } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EnvironmentMobile from '@/components/mobile/environment/EnvironmentMobile.vue';
import MaintenanceBadge from '@/components/monitoring/MaintenanceBadge.vue';
import ReadingFreshness from '@/components/monitoring/ReadingFreshness.vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import EffectiveRules from '@/components/monitoring/environment/EffectiveRules.vue';
import EnvironmentAlerts from '@/components/monitoring/environment/EnvironmentAlerts.vue';
import FailedJobTable from '@/components/monitoring/environment/FailedJobTable.vue';
import IncidentBanner from '@/components/monitoring/environment/IncidentBanner.vue';
import LongRunningJobs from '@/components/monitoring/environment/LongRunningJobs.vue';
import NodeCard from '@/components/monitoring/environment/NodeCard.vue';
import QueueTable from '@/components/monitoring/environment/QueueTable.vue';
import SilenceMenu from '@/components/monitoring/environment/SilenceMenu.vue';
import TrendChart from '@/components/monitoring/environment/TrendChart.vue';
import {
    failedTone,
    horizonStatusLabel,
    horizonStatusTone,
    pendingTone,
    statusText,
} from '@/components/monitoring/environment/readings';
import MetricTile from '@/components/nocturne/MetricTile.vue';
import StatusPill from '@/components/nocturne/StatusPill.vue';
import { useEnvironmentDetail } from '@/composables/useEnvironmentDetail';
import { useIsMobile } from '@/composables/useIsMobile';
import { useLivePoll } from '@/composables/useLivePoll';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { failedLabel, failedWindowNote } from '@/lib/failedWindow';
import {
    envColor,
    formatCount,
    formatWait,
    seriesRefreshInterval,
    waitColor,
} from '@/lib/monitoring';
import {
    index as applicationsIndex,
    show as showApplication,
} from '@/routes/applications';
import { testConnection } from '@/routes/environments';

defineOptions({
    layout: { title: 'Environment detail', live: true },
});

const { page, series } = defineProps<{
    page: App.Data.Pages.EnvironmentDetailPageData;
    series: App.Data.Pages.EnvironmentSeriesData;
}>();

useLivePoll();
useLivePoll(['series'], (ms) =>
    seriesRefreshInterval(ms, series.grid.stepSeconds),
);

const slug = useTeamSlug();
const isMobile = useIsMobile();
const { environment, threshold, incident, incidentAlert, lastKnown } =
    useEnvironmentDetail(() => page);

const longRunningUnknown = computed(
    () =>
        environment.value.readingError !== null ||
        environment.value.stale ||
        environment.value.status === null ||
        environment.value.status === 'unreachable',
);

type Tile = { label: string; value: string; color?: string; note: string };

const maxWaitNote = computed(() => {
    const seconds = threshold('queue.max_wait');

    return seconds === null
        ? trans('rule disabled')
        : trans('threshold :value', { value: `${seconds}s` });
});

const tiles = computed<Tile[]>(() => [
    {
        label: trans('Master'),
        value:
            environment.value.horizonStatus !== null
                ? horizonStatusLabel(environment.value.horizonStatus)
                : environment.value.status === null
                  ? statusText(environment.value)
                  : trans('unknown'),
        color: horizonStatusTone(environment.value),
        note:
            environment.value.readingError !== null &&
            environment.value.horizonStatus !== null
                ? trans('last known')
                : trans('nodes: :count', {
                      count: String(environment.value.nodeCount),
                  }),
    },
    {
        label: trans('Pending'),
        value: formatCount(environment.value.pending),
        color: pendingTone(
            environment.value.pending,
            threshold('queue.pending'),
        ),
        note: trans(':count queue', { count: String(page.queues.length) }),
    },
    {
        label: trans('Max wait'),
        value: formatWait(environment.value.maxWaitSeconds),
        color: waitColor(
            environment.value.maxWaitSeconds,
            threshold('queue.max_wait'),
        ),
        note: maxWaitNote.value,
    },
    {
        label: trans('Workers'),
        value: String(environment.value.workers),
        color:
            environment.value.status !== null && !environment.value.workers
                ? 'var(--st-down)'
                : 'var(--nc-text)',
        note: trans('active processes'),
    },
    {
        label: trans('Throughput'),
        value: String(environment.value.jobsPerMinute),
        color: 'var(--nc-text)',
        note: trans('jobs/min'),
    },
    {
        label: failedLabel(environment.value.failedWindowMinutes),
        value: formatCount(environment.value.failedInWindow),
        color: failedTone(
            environment.value.failedLastHour,
            threshold('jobs.failed_per_hour'),
        ),
        note: failedWindowNote(environment.value.failedWindowMinutes),
    },
]);
</script>

<template>
    <Head :title="`${environment.applicationName} · ${environment.name}`" />

    <EnvironmentMobile v-if="isMobile" :page="page" />

    <div
        v-else
        class="flex flex-col"
        style="padding: var(--nc-space-6); gap: var(--nc-space-4)"
    >
        <div class="flex flex-wrap items-center" style="gap: var(--nc-space-3)">
            <span
                class="h-[38px] w-1 flex-none rounded-[2px]"
                :style="{ background: envColor(environment.color) }"
            />
            <div class="min-w-0">
                <div class="nc-t-2xs nc-tone-muted">
                    <Link :href="applicationsIndex(slug)">{{
                        $t('Applications')
                    }}</Link>
                    /
                    <Link
                        :href="
                            showApplication({
                                current_team: slug,
                                application: environment.applicationId,
                            })
                        "
                        >{{ environment.applicationName }}</Link
                    >
                </div>
                <div
                    class="flex items-center"
                    style="font-size: 24px; line-height: 1.15; gap: 8px"
                >
                    {{ environment.name }}
                    <span
                        v-if="environment.isProduction"
                        class="nc-tone-muted inline-flex"
                        role="img"
                        :title="$t('Production environment')"
                        :aria-label="$t('Production environment')"
                    >
                        <PhShieldCheck :size="20" weight="bold" />
                    </span>
                </div>
            </div>
            <StatusPill
                v-if="environment.status"
                :status="environment.status"
            />
            <span
                v-else
                class="nc-t-xs nc-tone-muted"
                style="
                    border-radius: var(--nc-radius-sm);
                    padding: 3px 9px;
                    border: 1px solid var(--nc-neutral-700);
                "
                >{{ statusText(environment) }}</span
            >
            <MaintenanceBadge
                v-if="environment.maintenanceUntil"
                :until="environment.maintenanceUntil"
                :reason="environment.maintenanceReason"
            />
            <div
                class="ml-auto flex flex-wrap items-center justify-end"
                style="gap: var(--nc-space-2)"
            >
                <SilenceMenu
                    v-if="page.canSilence"
                    :environment-id="environment.id"
                    :choices="page.silenceChoices"
                />
                <ConnectionTest
                    v-if="page.canTestConnection"
                    class="flex-row-reverse"
                    :url="
                        testConnection({
                            current_team: slug,
                            environment: environment.id,
                        })
                    "
                    :payload="null"
                />
                <a
                    :href="environment.horizonUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="nc-btn nc-btn-primary nc-t-xs"
                >
                    <PhArrowSquareOut :size="14" />{{ $t('Open Horizon') }}
                </a>
            </div>
        </div>

        <div
            class="nc-t-2xs nc-tone-muted flex flex-wrap items-center"
            style="gap: 0 6px; letter-spacing: 0.01em"
        >
            <span class="min-w-0 break-all">{{ environment.horizonUrl }}</span>
            <template v-if="environment.usesBasicAuth">
                <span>·</span>
                <span>{{ $t('basic auth') }}</span>
            </template>
            <span>·</span>
            <ReadingFreshness
                :last-reading-at="environment.lastReadingAt"
                :stale="environment.stale"
                :polling-enabled="environment.pollingEnabled"
                :reading-error="environment.readingError"
            />
            <template v-if="environment.latencyMs !== null">
                <span>·</span>
                <span class="nc-num">{{
                    $t('last response :ms ms', {
                        ms: String(environment.latencyMs),
                    })
                }}</span>
            </template>
        </div>

        <IncidentBanner
            v-if="incident"
            :status="incident"
            :reading-error="environment.readingError"
            :reading-problem="environment.readingProblem"
            :alert="incidentAlert"
            :has-earlier-reading="page.nodes.length + page.queues.length > 0"
        />

        <EnvironmentAlerts v-if="page.alerts.length" :alerts="page.alerts" />

        <div
            class="grid"
            style="
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
                gap: var(--nc-space-3);
            "
        >
            <MetricTile
                v-for="tile in tiles"
                :key="tile.label"
                :label="tile.label"
                :value="tile.value"
                :color="tile.color"
                :note="tile.note"
            />
        </div>

        <section class="nc-card">
            <div
                class="mb-[var(--nc-space-3)] flex flex-wrap items-baseline gap-2"
            >
                <span style="font-size: 14px">{{
                    $t('Nodes (master supervisors)')
                }}</span>
                <span class="nc-t-2xs nc-tone-muted"
                    >{{ $t('one master per machine') }} ·
                    /horizon/api/masters</span
                >
                <span
                    v-if="lastKnown"
                    class="nc-t-2xs ml-auto"
                    style="color: var(--st-warn)"
                    >{{ lastKnown }}</span
                >
            </div>
            <div v-if="!page.nodes.length" class="nc-t-xs nc-tone-muted">
                {{ $t('No master supervisor in the latest reading.') }}
            </div>
            <div
                v-else
                class="grid"
                style="
                    grid-template-columns: repeat(
                        auto-fill,
                        minmax(224px, 1fr)
                    );
                    gap: var(--nc-space-3);
                "
            >
                <NodeCard
                    v-for="node in page.nodes"
                    :key="node.hostname"
                    :node="node"
                />
            </div>
        </section>

        <div class="detail-grid mt-[var(--nc-space-2)]">
            <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
                <TrendChart
                    :throughput="series.throughput"
                    :max-wait="series.maxWait"
                    :range="series.range"
                    :grid="series.grid"
                />
                <QueueTable
                    :queues="page.queues"
                    :note="lastKnown"
                    :wait-threshold="threshold('queue.max_wait')"
                />
                <FailedJobTable :jobs="page.failedJobs" :note="lastKnown" />
            </div>
            <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
                <LongRunningJobs
                    :jobs="page.longRunningJobs"
                    :threshold-seconds="threshold('job.runtime')"
                    :unknown="longRunningUnknown"
                />
                <EffectiveRules
                    :rules="page.rules"
                    :environment-name="page.environment.name"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.detail-grid {
    display: grid;
    align-items: start;
    gap: var(--nc-space-6);
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
}

@media (max-width: 1023px) {
    .detail-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
