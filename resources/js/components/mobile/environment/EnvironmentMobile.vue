<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    PhArrowSquareOut,
    PhCaretLeft,
    PhPencilSimple,
} from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import MaintenanceBadge from '@/components/monitoring/MaintenanceBadge.vue';
import PasswordNeededTag from '@/components/monitoring/PasswordNeededTag.vue';
import ReadingFreshness from '@/components/monitoring/ReadingFreshness.vue';
import EnvironmentAlerts from '@/components/monitoring/environment/EnvironmentAlerts.vue';
import IncidentBanner from '@/components/monitoring/environment/IncidentBanner.vue';
import SilenceMenu from '@/components/monitoring/environment/SilenceMenu.vue';
import QueueFlags from '@/components/monitoring/environment/QueueFlags.vue';
import {
    failedLastHourText,
    statusText,
    statusTone,
    thresholdTone,
} from '@/components/monitoring/environment/readings';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import MetricCard from '@/components/nocturne/MetricCard.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import StatusPill from '@/components/nocturne/StatusPill.vue';
import { useEnvironmentDetail } from '@/composables/useEnvironmentDetail';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { useVisibility } from '@/composables/useVisibility';
import { formatCount, formatSeconds } from '@/lib/monitoring';
import { show as showApplication } from '@/routes/applications';
import { edit as editEnvironment } from '@/routes/environments';

const { page } = defineProps<{
    page: App.Data.Pages.EnvironmentDetailPageData;
}>();

const slug = useTeamSlug();
const { canManageApplications } = useVisibility();
const { environment, incident, lastKnown } = useEnvironmentDetail(() => page);

const tiles = computed(() => [
    {
        label: trans('Pending'),
        value: formatCount(environment.value.pending),
        color: thresholdTone(
            environment.value,
            'queue.pending',
            environment.value.pending,
        ),
    },
    {
        label: trans('Max wait'),
        value: formatSeconds(environment.value.maxWaitSeconds),
        color: thresholdTone(
            environment.value,
            'queue.max_wait',
            environment.value.maxWaitSeconds,
        ),
    },
    {
        label: trans('Workers'),
        value: String(environment.value.workers),
    },
    {
        label: trans('Failed · last hour'),
        value: failedLastHourText(environment.value),
        color: thresholdTone(
            environment.value,
            'jobs.failed_per_hour',
            environment.value.failedLastHour,
        ),
    },
    {
        label: trans('Throughput'),
        value: `${environment.value.jobsPerMinute}/min`,
    },
    {
        label: trans('Nodes'),
        value: String(environment.value.nodeCount),
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
                class="nc-tone-soft flex flex-none items-center"
                :aria-label="$t('Back')"
            >
                <PhCaretLeft :size="17" />
            </Link>
            <span class="min-w-0">
                <span
                    class="nc-tone-faint block truncate"
                    style="font-size: 11px"
                    >{{ environment.applicationName }}</span
                >
                <EnvPill
                    :name="environment.name"
                    :type="environment.type"
                    :size="13"
                    class="mt-[2px]"
                />
            </span>
            <StatusPill
                class="ml-auto flex-none"
                :status="environment.status"
                :label="statusText(environment)"
                :tone="statusTone(environment)"
            />
        </div>

        <div
            class="flex flex-col"
            style="
                padding: var(--nc-space-3) var(--nc-space-4) var(--nc-space-4);
                gap: var(--nc-space-3);
            "
        >
            <div class="nc-t-2xs">
                <ReadingFreshness
                    :last-reading-at="environment.lastReadingAt"
                    :stale="environment.stale"
                    :polling-enabled="environment.pollingEnabled"
                    :reading-error="environment.readingError"
                    :hide-reason="incident !== null"
                />
            </div>

            <div v-if="environment.passwordNeeded">
                <PasswordNeededTag />
            </div>

            <div v-if="environment.maintenanceUntil">
                <MaintenanceBadge
                    :until="environment.maintenanceUntil"
                    :reason="environment.maintenanceReason"
                />
            </div>

            <IncidentBanner
                v-if="incident"
                compact
                :status="incident"
                :reading-error="environment.readingError"
                :reading-problem="environment.readingProblem"
                :has-earlier-reading="
                    page.nodes.length + page.queues.length > 0
                "
            />

            <EnvironmentAlerts
                v-if="page.alerts.length"
                class="mobile-card"
                :alerts="page.alerts"
                compact
            />

            <div class="grid grid-cols-3" style="gap: var(--nc-space-2)">
                <MetricCard
                    v-for="tile in tiles"
                    :key="tile.label"
                    size="compact"
                    :label="tile.label"
                    :value="tile.value"
                    :color="tile.color"
                />
            </div>

            <div class="nc-card mobile-card">
                <div class="mb-[var(--nc-space-2)] flex items-baseline gap-2">
                    <span class="nc-t-sm">{{ $t('Queues') }}</span>
                    <span
                        v-if="lastKnown"
                        class="ml-auto"
                        style="font-size: 11px; color: var(--st-warn)"
                        >{{ lastKnown }}</span
                    >
                    <span
                        v-else
                        class="nc-tone-faint ml-auto"
                        style="font-size: 11px"
                        >{{ $t('pending · wait') }}</span
                    >
                </div>
                <div v-if="!page.queues.length" class="nc-t-xs nc-tone-muted">
                    {{ $t('No queue in the latest reading.') }}
                </div>
                <div
                    v-for="queue in page.queues"
                    :key="queue.name"
                    class="nc-t-xs flex items-center gap-2"
                    style="margin-top: var(--nc-space-2)"
                >
                    <span
                        class="min-w-0 truncate"
                        style="letter-spacing: 0.01em"
                        >{{ queue.name }}</span
                    >
                    <QueueFlags
                        class="flex-none"
                        :queue="queue"
                        :thresholds="environment.thresholds"
                        quiet
                    />
                    <span
                        class="nc-num ml-auto flex-none"
                        style="color: var(--nc-neutral-300)"
                        >{{ formatCount(queue.pending) }}</span
                    >
                    <span
                        class="nc-num nc-tone-soft min-w-[42px] flex-none text-right whitespace-nowrap"
                        >{{ formatSeconds(queue.waitSeconds) }}</span
                    >
                </div>
            </div>

            <div class="nc-card mobile-card">
                <div class="mb-[var(--nc-space-2)] flex items-baseline gap-2">
                    <span class="nc-t-sm">{{ $t('Nodes') }}</span>
                    <span
                        v-if="lastKnown"
                        class="ml-auto"
                        style="font-size: 11px; color: var(--st-warn)"
                        >{{ lastKnown }}</span
                    >
                </div>
                <div v-if="!page.nodes.length" class="nc-t-xs nc-tone-muted">
                    {{ $t('No master supervisor in the latest reading.') }}
                </div>
                <div
                    v-for="node in page.nodes"
                    :key="node.hostname"
                    class="nc-t-xs flex items-center gap-2"
                    style="margin-top: var(--nc-space-2)"
                >
                    <StatusLamp :status="node.status" :size="6" />
                    <span
                        class="min-w-0 truncate"
                        style="letter-spacing: 0.01em"
                        >{{ node.hostname }}</span
                    >
                    <span class="nc-num nc-tone-muted ml-auto flex-none">{{
                        $tChoice(':count worker|:count workers', node.workers)
                    }}</span>
                </div>
            </div>

            <div class="flex flex-wrap" style="gap: var(--nc-space-2)">
                <SilenceMenu
                    v-if="page.canSilence"
                    :environment-id="environment.id"
                    :choices="page.silenceChoices"
                    block
                />
                <Link
                    v-if="canManageApplications"
                    class="nc-btn nc-btn-secondary nc-t-xs"
                    :href="
                        editEnvironment({
                            current_team: slug,
                            environment: environment.id,
                        })
                    "
                    :title="$t('Edit environment')"
                    :aria-label="$t('Edit environment')"
                >
                    <PhPencilSimple :size="13" />
                </Link>
                <a
                    :href="environment.horizonUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="nc-btn nc-btn-primary nc-t-xs flex-1 justify-center"
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
}
</style>
