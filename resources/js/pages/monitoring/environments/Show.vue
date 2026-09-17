<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowSquareOut } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EnvironmentMobile from '@/components/mobile/environment/EnvironmentMobile.vue';
import ReadingFreshness from '@/components/monitoring/ReadingFreshness.vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import ConnectionCard from '@/components/monitoring/environment/ConnectionCard.vue';
import EffectiveRules from '@/components/monitoring/environment/EffectiveRules.vue';
import FailedJobTable from '@/components/monitoring/environment/FailedJobTable.vue';
import IncidentBanner from '@/components/monitoring/environment/IncidentBanner.vue';
import LongRunningJobs from '@/components/monitoring/environment/LongRunningJobs.vue';
import NodeCard from '@/components/monitoring/environment/NodeCard.vue';
import QueueTable from '@/components/monitoring/environment/QueueTable.vue';
import TrendChart from '@/components/monitoring/environment/TrendChart.vue';
import {
    failedColor,
    formatAge,
    horizonStatusLabel,
    horizonStatusTone,
    pendingTone,
    statusText,
} from '@/components/monitoring/environment/readings';
import MetricTile from '@/components/nocturne/MetricTile.vue';
import StatusPill from '@/components/nocturne/StatusPill.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useLivePoll } from '@/composables/useLivePoll';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { failedLabel, failedWindowNote } from '@/lib/failedWindow';
import { envColor, formatCount, formatWait, waitColor } from '@/lib/monitoring';
import {
    index as applicationsIndex,
    show as showApplication,
} from '@/routes/applications';
import { testConnection } from '@/routes/environments';

defineOptions({
    layout: { title: 'Environment detail', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.EnvironmentDetailPageData;
}>();

useLivePoll(['page', 'openAlertCount']);

const slug = useTeamSlug();
const isMobile = useIsMobile();
const environment = computed(() => page.environment);

const threshold = (metric: App.Enums.AlertRuleMetric) =>
    page.thresholds[metric] ?? 0;

const longRunningUnknown = computed(
    () =>
        environment.value.readingError !== null ||
        environment.value.stale ||
        environment.value.status === null ||
        environment.value.status === 'unreachable',
);

const incident = computed(() => {
    const status = environment.value.status;

    return status !== null && status !== 'active' ? status : null;
});

const lastKnown = computed(() => {
    if (environment.value.readingError === null) {
        return null;
    }

    const ages = page.nodes.map((node) => node.seenSecondsAgo);

    return ages.length
        ? trans('last known · :time ago', {
              time: formatAge(Math.min(...ages)),
          })
        : trans('last known');
});

type Tile = { label: string; value: string; color: string; note: string };

const tiles = computed<Tile[]>(() => [
    {
        label: 'Master',
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
        label: 'Pending',
        value: formatCount(environment.value.pending),
        color: pendingTone(
            environment.value.pending,
            threshold('queue.pending'),
        ),
        note: trans(':count queue', { count: String(page.queues.length) }),
    },
    {
        label: 'Max wait',
        value: formatWait(environment.value.maxWaitSeconds),
        color: waitColor(
            environment.value.maxWaitSeconds,
            threshold('queue.max_wait'),
        ),
        note: trans('threshold :value', {
            value: `${threshold('queue.max_wait')}s`,
        }),
    },
    {
        label: 'Workers',
        value: String(environment.value.workers),
        color:
            environment.value.status !== null && !environment.value.workers
                ? 'var(--st-down)'
                : 'var(--nc-text)',
        note: trans('active processes'),
    },
    {
        label: 'Throughput',
        value: String(environment.value.jobsPerMinute),
        color: 'var(--nc-text)',
        note: 'jobs/min',
    },
    {
        label: failedLabel(environment.value.failedWindowMinutes),
        value: formatCount(environment.value.failedInWindow),
        color: failedColor(
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
                <div style="font-size: 11px; color: var(--nc-neutral-500)">
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
                <div style="font-size: 24px; line-height: 1.15">
                    {{ environment.name }}
                </div>
            </div>
            <StatusPill
                v-if="environment.status"
                :status="environment.status"
            />
            <span
                v-else
                style="
                    font-size: 12px;
                    border-radius: var(--nc-radius-sm);
                    padding: 3px 9px;
                    color: var(--nc-neutral-500);
                    border: 1px solid var(--nc-neutral-700);
                "
                >{{ statusText(environment) }}</span
            >
            <div
                class="ml-auto flex flex-wrap items-center justify-end"
                style="gap: var(--nc-space-2)"
            >
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
                    class="nc-btn nc-btn-primary"
                    style="font-size: 12px"
                >
                    <PhArrowSquareOut :size="14" />{{ $t('Open Horizon') }}
                </a>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center"
            style="
                gap: 0 6px;
                font-size: 11px;
                color: var(--nc-neutral-500);
                letter-spacing: 0.01em;
            "
        >
            <span class="min-w-0 break-all">{{ environment.horizonUrl }}</span>
            <template v-if="environment.basicAuthUser">
                <span>·</span>
                <span>basic auth: {{ environment.basicAuthUser }}</span>
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
            :alert="page.openAlert"
            :has-earlier-reading="page.nodes.length + page.queues.length > 0"
        />

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
                <span style="font-size: 11px; color: var(--nc-neutral-500)"
                    >{{ $t('one master per machine') }} ·
                    /horizon/api/masters</span
                >
                <span
                    v-if="lastKnown"
                    class="ml-auto"
                    style="font-size: 11px; color: var(--st-warn)"
                    >{{ lastKnown }}</span
                >
            </div>
            <div
                v-if="!page.nodes.length"
                style="font-size: 12px; color: var(--nc-neutral-500)"
            >
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

        <div
            class="mt-[var(--nc-space-2)] grid items-start"
            style="
                grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
                gap: var(--nc-space-6);
            "
        >
            <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
                <TrendChart
                    :throughput="page.throughput"
                    :max-wait="page.maxWait"
                    :range="page.range"
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
                <EffectiveRules :rules="page.rules" />
                <ConnectionCard :environment="environment" />
            </div>
        </div>
    </div>
</template>
