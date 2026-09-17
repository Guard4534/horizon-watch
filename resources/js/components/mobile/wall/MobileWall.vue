<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { failedPerHour } from '@/lib/failedWindow';
import {
    envColor,
    formatCount,
    formatWait,
    pendingColor,
    statusColor,
    statusLabel,
    waitColor,
} from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

// A list, not groups: with a handful of rows on screen a group header
// costs more room than it saves, so the application name sits in the row.
// The server order already puts the environments in trouble on top.
const { environments, problems, kpis, failedPerHourThreshold } = defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
    problems: App.Data.Monitoring.EnvironmentData[];
    kpis: App.Data.Pages.WallKpisData;
    failedPerHourThreshold: number;
}>();

const filter = defineModel<'all' | 'problems'>('filter', { required: true });

const slug = useTeamSlug();

const rows = computed(() =>
    filter.value === 'problems' ? problems : environments,
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
                    {{ kpis.environmentsUp }}/{{ kpis.environmentsTotal }}
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-label">{{ $t('Issues') }}</div>
                <div
                    class="kpi-value"
                    :style="{
                        color: problems.length
                            ? 'var(--st-down)'
                            : 'var(--st-ok)',
                    }"
                >
                    {{ problems.length }}
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Pending</div>
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
                    label: `${$t('Problems')} ${problems.length}`,
                },
                { value: 'all', label: `${$t('All')} ${environments.length}` },
            ]"
        />

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
                                color: pendingColor(environment.pending),
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
                            color: waitColor(environment.maxWaitSeconds),
                        }"
                        >{{ formatWait(environment.maxWaitSeconds) }} wait</span
                    >
                    <span
                        :style="{
                            color:
                                failedPerHour(
                                    environment.failedLast24Hours,
                                    environment.failedWindowMinutes,
                                ) > failedPerHourThreshold
                                    ? 'var(--st-warn)'
                                    : undefined,
                        }"
                        >{{ environment.failedLast24Hours }} failed</span
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
                {{ $t('Nothing to handle: every environment is running.') }}
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
