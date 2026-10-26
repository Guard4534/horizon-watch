<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import MetricCard from '@/components/nocturne/MetricCard.vue';
import MaintenanceBadge from '@/components/monitoring/MaintenanceBadge.vue';
import {
    calmStyle,
    failedLastHourText,
    needsAttention,
    statusText,
    statusTone,
    thresholdTone,
    troubledStyle,
} from '@/components/monitoring/environment/readings';
import { useTeamSlug } from '@/composables/useTeamSlug';
import type { WallKpiKey } from '@/composables/useWallKpis';
import { useWallKpis } from '@/composables/useWallKpis';
import { failedWindowNote, failedWindowShort } from '@/lib/failedWindow';
import { formatCount, formatSeconds, statusIcon } from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

const MOBILE_KPIS: WallKpiKey[] = ['healthy', 'openAlerts', 'pending'];

const {
    rows,
    totalCount,
    attentionCount,
    waitingCount,
    typeLabel,
    search,
    kpis,
} = defineProps<{
    rows: App.Data.Monitoring.EnvironmentData[];
    totalCount: number;
    attentionCount: number;
    waitingCount: number;
    typeLabel: string;
    search: string;
    kpis: App.Data.Pages.WallKpisData;
}>();

const emit = defineEmits<{ clear: [] }>();

const filter = defineModel<'all' | 'problems'>('filter', { required: true });

const slug = useTeamSlug();

const wallKpis = useWallKpis(() => kpis);
const tiles = computed(() =>
    wallKpis.value.filter((kpi) => MOBILE_KPIS.includes(kpi.key)),
);

const narrowing = computed(() =>
    [typeLabel, search.trim() ? `"${search.trim()}"` : '']
        .filter(Boolean)
        .join(' · '),
);

function rowStyle(environment: App.Data.Monitoring.EnvironmentData) {
    return needsAttention(environment)
        ? troubledStyle(statusTone(environment))
        : calmStyle();
}
</script>

<template>
    <div class="flex flex-col" style="gap: var(--nc-space-3)">
        <div class="flex" style="gap: var(--nc-space-2)">
            <MetricCard
                v-for="kpi in tiles"
                :key="kpi.key"
                size="compact"
                class="flex-1"
                :label="kpi.label"
                :value="kpi.value"
                :color="kpi.color"
            />
        </div>

        <SegmentedControl
            v-model="filter"
            name="mobile-wall-filter"
            class="nc-seg-full"
            :options="[
                {
                    value: 'problems',
                    label: `${$t('Needs attention')} ${attentionCount}`,
                },
                { value: 'all', label: `${$t('All')} ${totalCount}` },
            ]"
        />

        <div v-if="narrowing" class="narrowing">
            <span class="min-w-0 truncate">{{
                $t('Filtered by :filters', { filters: narrowing })
            }}</span>
            <button
                type="button"
                class="nc-btn nc-btn-ghost nc-t-xs"
                @click="emit('clear')"
            >
                {{ $t('Clear filters') }}
            </button>
        </div>

        <div class="flex flex-col" style="gap: var(--nc-space-2)">
            <Link
                v-for="environment in rows"
                :key="environment.id"
                :href="
                    showEnvironment({
                        current_team: slug,
                        environment: environment.id,
                    })
                "
                class="row"
                :style="rowStyle(environment)"
            >
                <span class="flex items-center gap-[9px]">
                    <span class="min-w-0 flex-1">
                        <span class="nc-t-sm block truncate">{{
                            environment.applicationName
                        }}</span>
                        <EnvPill
                            :name="environment.name"
                            :type="environment.type"
                            :size="11"
                            style="margin-top: 3px"
                        />
                    </span>
                    <span class="flex-none text-right">
                        <span
                            class="nc-num block"
                            style="font-size: 18px; line-height: 1.2"
                            :style="{
                                color: thresholdTone(
                                    environment,
                                    'queue.pending',
                                    environment.pending,
                                ),
                            }"
                            >{{ formatCount(environment.pending) }}</span
                        >
                        <span class="nc-micro nc-tone-faint block">{{
                            $t('pending')
                        }}</span>
                    </span>
                </span>
                <span
                    class="nc-num nc-t-2xs nc-tone-faint mt-2 flex flex-wrap items-center"
                    style="gap: 2px 8px"
                >
                    <span
                        class="inline-flex items-center gap-[4px]"
                        style="font-weight: 500"
                        :style="{ color: statusTone(environment) }"
                        ><component
                            :is="statusIcon(environment.status!)"
                            v-if="needsAttention(environment)"
                            :size="13"
                            weight="bold"
                        />{{ statusText(environment) }}</span
                    >
                    <MaintenanceBadge
                        v-if="environment.maintenanceUntil"
                        :until="environment.maintenanceUntil"
                        :reason="environment.maintenanceReason"
                        compact
                    />
                    <span
                        class="ml-auto"
                        :style="{
                            color:
                                thresholdTone(
                                    environment,
                                    'queue.max_wait',
                                    environment.maxWaitSeconds,
                                ) ?? 'var(--nc-neutral-400)',
                        }"
                        >{{
                            $t(':wait wait', {
                                wait: formatSeconds(environment.maxWaitSeconds),
                            })
                        }}</span
                    >
                    <span
                        :title="
                            failedWindowNote(environment.failedWindowMinutes)
                        "
                        ><span
                            :style="{
                                color: thresholdTone(
                                    environment,
                                    'jobs.failed_per_hour',
                                    environment.failedLastHour,
                                ),
                            }"
                            >{{
                                $t(':count failed/h', {
                                    count: failedLastHourText(environment),
                                })
                            }}</span
                        >
                        · {{ formatCount(environment.failedInWindow) }}
                        {{
                            failedWindowShort(environment.failedWindowMinutes)
                        }}</span
                    >
                </span>
            </Link>
            <p
                v-if="rows.length === 0"
                class="nc-t-xs nc-tone-muted"
                style="
                    margin: 0;
                    padding: var(--nc-space-4) 0;
                    text-align: center;
                "
            >
                <template v-if="narrowing">{{
                    $t('No environment matches these filters.')
                }}</template>
                <template v-else-if="waitingCount > 0">{{
                    $t(
                        'Nothing needs attention. Some environments have no reading yet.',
                    )
                }}</template>
                <template v-else>{{ $t('Nothing needs attention.') }}</template>
            </p>
        </div>
    </div>
</template>

<style scoped>
.narrowing {
    display: flex;
    align-items: center;
    gap: var(--nc-space-2);
    font-size: 12px;
    color: var(--nc-neutral-400);
}

.row {
    position: relative;
    display: block;
    width: 100%;
    padding: var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    color: inherit;
    text-decoration: none;
}

.row:hover {
    box-shadow: var(--nc-shadow-md) !important;
}
</style>
