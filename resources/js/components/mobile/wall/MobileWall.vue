<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { pendingTone } from '@/components/monitoring/environment/readings';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { thresholdOf } from '@/lib/alertRules';
import { failedWindowNote, failedWindowShort } from '@/lib/failedWindow';
import {
    envColor,
    formatCount,
    formatWait,
    statusColor,
    statusLabel,
    waitColor,
} from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

const {
    rows,
    totalCount,
    problemCount,
    waitingCount,
    environmentName,
    search,
    kpis,
} = defineProps<{
    rows: App.Data.Monitoring.EnvironmentData[];
    totalCount: number;
    problemCount: number;
    waitingCount: number;
    environmentName: string;
    search: string;
    kpis: App.Data.Pages.WallKpisData;
}>();

const emit = defineEmits<{ clear: [] }>();

const filter = defineModel<'all' | 'problems'>('filter', { required: true });

const slug = useTeamSlug();

const narrowing = computed(() =>
    [environmentName, search.trim() ? `"${search.trim()}"` : '']
        .filter(Boolean)
        .join(' · '),
);

function troubled(environment: App.Data.Monitoring.EnvironmentData): boolean {
    return environment.status !== null && environment.status !== 'active';
}

function rowStyle(environment: App.Data.Monitoring.EnvironmentData) {
    if (!troubled(environment)) {
        return {
            background: 'var(--nc-surface)',
            boxShadow: 'var(--nc-shadow-sm)',
            opacity: 0.82,
        };
    }

    const color = statusColor(environment.status!);

    return {
        background: `color-mix(in srgb, ${color} 15%, var(--nc-surface))`,
        boxShadow: `var(--nc-shadow-sm), 0 0 0 3px color-mix(in srgb, ${color} 16%, transparent)`,
    };
}
</script>

<template>
    <div class="flex flex-col" style="gap: var(--nc-space-3)">
        <div class="flex" style="gap: var(--nc-space-2)">
            <div class="kpi">
                <div class="kpi-label">{{ $t('Up') }}</div>
                <div class="kpi-value">
                    {{ kpis.environmentsActive }}/{{ kpis.environmentsTotal }}
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-label">{{ $t('Issues') }}</div>
                <div
                    class="kpi-value"
                    :style="{
                        color: problemCount ? 'var(--st-down)' : 'var(--st-ok)',
                    }"
                >
                    {{ problemCount }}
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-label">{{ $t('Pending') }}</div>
                <div class="kpi-value">
                    {{ formatCount(kpis.pendingTotal) }}
                </div>
            </div>
        </div>

        <SegmentedControl
            v-model="filter"
            name="mobile-wall-filter"
            class="mobile-seg"
            :options="[
                {
                    value: 'problems',
                    label: `${$t('Problems')} ${problemCount}`,
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
                class="nc-btn nc-btn-ghost"
                style="font-size: 12px"
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
                <span
                    class="absolute top-0 bottom-0 left-0 w-[4px]"
                    :style="{ background: envColor(environment.color) }"
                />
                <span class="flex items-center gap-[9px]">
                    <StatusLamp :status="environment.status" glow />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate" style="font-size: 13px">{{
                            environment.applicationName
                        }}</span>
                        <EnvPill
                            :name="environment.name"
                            :color="environment.color"
                            :size="11"
                            style="margin-top: 3px"
                        />
                    </span>
                    <span class="flex-none text-right">
                        <span
                            class="nc-num block"
                            style="font-size: 16px; line-height: 1.2"
                            :style="{
                                color: pendingTone(
                                    environment.pending,
                                    thresholdOf(environment, 'queue.pending'),
                                ),
                            }"
                            >{{ formatCount(environment.pending) }}</span
                        >
                        <span class="unit block">pending</span>
                    </span>
                </span>
                <span
                    class="nc-num mt-2 flex items-center gap-2"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
                    <span
                        v-if="environment.status !== null"
                        :style="{ color: statusColor(environment.status) }"
                        >{{ statusLabel(environment.status) }}</span
                    >
                    <span v-else>{{ $t('No reading yet') }}</span>
                    <span
                        class="ml-auto"
                        :style="{
                            color: waitColor(
                                environment.maxWaitSeconds,
                                thresholdOf(environment, 'queue.max_wait'),
                            ),
                        }"
                        >{{
                            $t(':wait wait', {
                                wait: formatWait(environment.maxWaitSeconds),
                            })
                        }}</span
                    >
                    <span
                        :title="
                            failedWindowNote(environment.failedWindowMinutes)
                        "
                        :style="{
                            color:
                                environment.failedLastHour >
                                thresholdOf(environment, 'jobs.failed_per_hour')
                                    ? 'var(--st-warn)'
                                    : undefined,
                        }"
                        >{{
                            $t(':count failed', {
                                count: String(environment.failedInWindow),
                            })
                        }}
                        ·
                        {{
                            failedWindowShort(environment.failedWindowMinutes)
                        }}</span
                    >
                </span>
            </Link>
            <p
                v-if="rows.length === 0"
                style="
                    margin: 0;
                    padding: var(--nc-space-4) 0;
                    text-align: center;
                    font-size: 12px;
                    color: var(--nc-neutral-500);
                "
            >
                <template v-if="narrowing">{{
                    $t('No environment matches these filters.')
                }}</template>
                <template v-else-if="waitingCount > 0">{{
                    $t(
                        'Nothing to handle. Some environments are still waiting for their first reading.',
                    )
                }}</template>
                <template v-else>{{
                    $t('Nothing to handle: every environment is running.')
                }}</template>
            </p>
        </div>
    </div>
</template>

<style scoped>
.kpi {
    flex: 1;
    min-width: 0;
    padding: var(--nc-space-2) var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
}

.kpi-label,
.unit {
    font-size: 9px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--nc-neutral-600);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.kpi-value {
    margin-top: 2px;
    font-size: 19px;
    line-height: 1.15;
}

.mobile-seg {
    display: flex;
    width: 100%;
}

.mobile-seg :deep(.nc-seg-opt) {
    flex: 1;
    justify-content: center;
    font-size: 12px;
}

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
    padding: var(--nc-space-3) var(--nc-space-3) var(--nc-space-3)
        var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    color: inherit;
    text-decoration: none;
    overflow: hidden;
}

.row:hover {
    box-shadow: var(--nc-shadow-md) !important;
}
</style>
