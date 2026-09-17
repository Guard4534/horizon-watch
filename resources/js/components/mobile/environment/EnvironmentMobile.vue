<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    PhArrowSquareOut,
    PhBellSlash,
    PhCaretLeft,
} from '@phosphor-icons/vue';
import { computed } from 'vue';
import ReadingFreshness from '@/components/monitoring/ReadingFreshness.vue';
import IncidentBanner from '@/components/monitoring/environment/IncidentBanner.vue';
import {
    failedColor,
    formatAge,
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { failedLabel } from '@/lib/failedWindow';
import {
    formatCount,
    formatWait,
    pendingColor,
    statusColor,
    waitColor,
} from '@/lib/monitoring';
import { show as showApplication } from '@/routes/applications';

// The phone view helps to understand, not to diagnose: six numbers, queues
// and nodes as lists; no charts, failed-job table or rules.
const { page } = defineProps<{
    page: App.Data.Pages.EnvironmentDetailPageData;
}>();

const slug = useTeamSlug();
const environment = computed(() => page.environment);

const incident = computed(() => {
    const status = environment.value.status;

    return status !== null && status !== 'active' ? status : null;
});

const lastKnownAge = computed(() => {
    if (environment.value.readingError === null || !page.nodes.length) {
        return null;
    }

    return formatAge(
        Math.min(...page.nodes.map((node) => node.seenSecondsAgo)),
    );
});

// Metric names stay English in both languages, as on the desktop tiles.
const tiles = computed(() => [
    {
        label: 'Pending',
        value: formatCount(environment.value.pending),
        color: pendingColor(environment.value.pending),
    },
    {
        label: 'Max wait',
        value: formatWait(environment.value.maxWaitSeconds),
        color: waitColor(environment.value.maxWaitSeconds),
    },
    {
        label: 'Workers',
        value: String(environment.value.workers),
        color:
            environment.value.status !== null && !environment.value.workers
                ? 'var(--st-down)'
                : 'var(--nc-text)',
    },
    {
        label: failedLabel(environment.value.failedWindowMinutes),
        value: formatCount(environment.value.failedLast24Hours),
        color: failedColor(
            environment.value.failedLast24Hours,
            environment.value.failedWindowMinutes,
            page.thresholds['jobs.failed_per_hour'] ?? 0,
        ),
    },
    {
        label: 'Jobs/min',
        value: String(environment.value.jobsPerMinute),
        color: 'var(--nc-text)',
    },
    {
        label: 'Nodes',
        value: String(environment.value.nodeCount),
        color: 'var(--nc-text)',
    },
]);
</script>

<template>
    <div class="flex flex-col">
        <div
            class="flex items-center"
            style="
                gap: 9px;
                padding: var(--nc-space-3) var(--nc-space-4);
                border-bottom: 1px solid var(--nc-divider);
            "
        >
            <Link
                :href="
                    showApplication({
                        current_team: slug,
                        application: environment.applicationId,
                    })
                "
                class="flex flex-none items-center"
                style="color: var(--nc-neutral-400)"
                :aria-label="$t('Back')"
            >
                <PhCaretLeft :size="17" />
            </Link>
            <span class="min-w-0">
                <span
                    class="block truncate"
                    style="font-size: 10px; color: var(--nc-neutral-600)"
                    >{{ environment.applicationName }}</span
                >
                <EnvPill
                    :name="environment.name"
                    :color="environment.color"
                    :size="13"
                    class="mt-[2px]"
                />
            </span>
            <span
                class="ml-auto inline-flex flex-none items-center gap-[5px]"
                style="font-size: 10px"
                :style="{ color: statusTone(environment) }"
            >
                <StatusLamp :status="environment.status" :size="6" />{{
                    statusText(environment)
                }}
            </span>
        </div>

        <div
            class="flex flex-col"
            style="
                padding: var(--nc-space-3) var(--nc-space-4) var(--nc-space-4);
                gap: var(--nc-space-3);
            "
        >
            <div style="font-size: 11px">
                <ReadingFreshness
                    :last-reading-at="environment.lastReadingAt"
                    :stale="environment.stale"
                    :polling-enabled="environment.pollingEnabled"
                    :reading-error="environment.readingError"
                />
            </div>

            <IncidentBanner
                v-if="incident"
                compact
                :status="incident"
                :reading-error="environment.readingError"
                :alert="page.openAlert"
                :has-earlier-reading="
                    page.nodes.length + page.queues.length > 0
                "
            />

            <div class="grid grid-cols-3" style="gap: var(--nc-space-2)">
                <div
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="min-w-0"
                    style="
                        padding: var(--nc-space-2) var(--nc-space-3);
                        border-radius: var(--nc-radius-md);
                        background: var(--nc-surface);
                        box-shadow: var(--nc-shadow-sm);
                    "
                >
                    <div
                        class="truncate"
                        style="
                            font-size: 9px;
                            letter-spacing: 0.08em;
                            text-transform: uppercase;
                            color: var(--nc-neutral-600);
                        "
                    >
                        {{ tile.label }}
                    </div>
                    <div
                        class="nc-num"
                        style="
                            font-size: 17px;
                            line-height: 1.2;
                            margin-top: 2px;
                        "
                        :style="{ color: tile.color }"
                    >
                        {{ tile.value }}
                    </div>
                </div>
            </div>

            <div class="mobile-card">
                <div class="mb-[var(--nc-space-2)] flex items-baseline gap-2">
                    <span style="font-size: 13px">Queues</span>
                    <span
                        class="ml-auto"
                        style="font-size: 10px; color: var(--nc-neutral-600)"
                        >pending · wait</span
                    >
                </div>
                <div
                    v-if="!page.queues.length"
                    style="font-size: 12px; color: var(--nc-neutral-500)"
                >
                    {{ $t('No queue in the latest reading.') }}
                </div>
                <div
                    v-for="queue in page.queues"
                    :key="queue.name"
                    class="flex items-center gap-2"
                    style="font-size: 12px; margin-top: var(--nc-space-2)"
                >
                    <span
                        class="size-[5px] flex-none rounded-full"
                        :style="{ background: statusColor(queue.status) }"
                    />
                    <span
                        class="min-w-0 truncate"
                        style="letter-spacing: 0.01em"
                        >{{ queue.name }}</span
                    >
                    <span
                        class="nc-num ml-auto flex-none"
                        style="color: var(--nc-neutral-300)"
                        >{{ formatCount(queue.pending) }}</span
                    >
                    <span
                        class="nc-num w-[42px] flex-none text-right"
                        :style="{ color: waitColor(queue.waitSeconds) }"
                        >{{ formatWait(queue.waitSeconds) }}</span
                    >
                </div>
            </div>

            <div class="mobile-card">
                <div class="mb-[var(--nc-space-2)] flex items-baseline gap-2">
                    <span style="font-size: 13px">{{ $t('Nodes') }}</span>
                    <span
                        v-if="environment.readingError !== null"
                        class="ml-auto"
                        style="font-size: 10px; color: var(--st-warn)"
                        >{{
                            lastKnownAge
                                ? $t('last known · :time ago', {
                                      time: lastKnownAge,
                                  })
                                : $t('last known')
                        }}</span
                    >
                </div>
                <div
                    v-if="!page.nodes.length"
                    style="font-size: 12px; color: var(--nc-neutral-500)"
                >
                    {{ $t('No master supervisor in the latest reading.') }}
                </div>
                <div
                    v-for="node in page.nodes"
                    :key="node.hostname"
                    class="flex items-center gap-2"
                    style="font-size: 12px; margin-top: var(--nc-space-2)"
                >
                    <StatusLamp :status="node.status" :size="6" />
                    <span
                        class="min-w-0 truncate"
                        style="letter-spacing: 0.01em"
                        >{{ node.hostname }}</span
                    >
                    <span
                        class="nc-num ml-auto flex-none"
                        style="color: var(--nc-neutral-500)"
                        >{{ node.workers }} workers</span
                    >
                </div>
            </div>

            <div class="flex" style="gap: var(--nc-space-2)">
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary flex-1 justify-center"
                    style="font-size: 12px"
                    disabled
                    :title="$t('Available soon')"
                >
                    <PhBellSlash :size="13" />{{ $t('Mute') }}
                </button>
                <a
                    :href="environment.horizonUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="nc-btn nc-btn-primary flex-1 justify-center"
                    style="font-size: 12px"
                >
                    <PhArrowSquareOut :size="13" />Horizon
                </a>
            </div>
        </div>
    </div>
</template>

<style scoped>
.mobile-card {
    padding: var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
}
</style>
