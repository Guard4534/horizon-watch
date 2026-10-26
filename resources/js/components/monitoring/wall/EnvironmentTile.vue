<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhMinus, PhTrendDown, PhTrendUp } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import MaintenanceBadge from '@/components/monitoring/MaintenanceBadge.vue';
import {
    calmStyle,
    displayState,
    failedLastHourText,
    hasMeasurement,
    needsAttention,
    statusText,
    statusTone,
    thresholdTone,
    troubledStyle,
} from '@/components/monitoring/environment/readings';
import { useEnvironmentHref } from '@/composables/useEnvironmentHref';
import { failedWindowNote, failedWindowShort } from '@/lib/failedWindow';
import { formatCount, formatSeconds, statusIcon } from '@/lib/monitoring';

const { environment } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
}>();

const environmentHref = useEnvironmentHref();

const troubled = computed(() => needsAttention(environment));
const tint = computed(() => statusTone(environment));
const showsState = computed(() => displayState(environment) !== 'active');
const measured = computed(() => hasMeasurement(environment));

const tileStyle = computed(() =>
    troubled.value ? troubledStyle(tint.value) : calmStyle(),
);

const urgent = computed(
    () =>
        environment.status === 'inactive' ||
        environment.status === 'unreachable',
);

const trend = computed(() => {
    const percent = environment.trendPercent;

    if (percent === null || percent === 0) {
        return { icon: PhMinus, label: percent === 0 ? '0%' : '' };
    }

    return percent > 0
        ? { icon: PhTrendUp, label: `+${percent}%` }
        : { icon: PhTrendDown, label: `${percent}%` };
});
</script>

<template>
    <Link
        :href="environmentHref(environment.id)"
        class="tile"
        :style="tileStyle"
    >
        <span class="flex flex-wrap items-center" style="gap: 4px 7px">
            <span class="min-w-0 flex-auto">
                <EnvPill :name="environment.name" :type="environment.type" />
            </span>
            <MaintenanceBadge
                v-if="environment.maintenanceUntil"
                :until="environment.maintenanceUntil"
                :reason="environment.maintenanceReason"
                compact
            />
            <span v-if="showsState" class="state" :style="{ color: tint }">
                <component
                    :is="statusIcon(environment.status!)"
                    v-if="troubled"
                    :size="14"
                    weight="bold"
                    :class="{ 'nc-pulse': urgent }"
                />{{ statusText(environment) }}
            </span>
        </span>
        <span class="nc-num mt-[10px] flex items-baseline gap-[6px]">
            <span
                class="reading"
                :style="{
                    color: thresholdTone(
                        environment,
                        'queue.pending',
                        environment.pending,
                    ),
                }"
                >{{ formatCount(environment.pending) }}</span
            >
            <span class="nc-micro nc-tone-faint">{{ $t('pending') }}</span>
            <span
                class="nc-t-xs ml-auto"
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
        </span>
        <span v-if="environment.trend.length > 1" class="mt-[6px] block">
            <TrendLine
                :values="environment.trend"
                :width="150"
                :height="18"
                color="var(--nc-neutral-500)"
            />
        </span>
        <span
            class="nc-num nc-micro nc-tone-faint mt-[6px] flex flex-wrap items-center"
            style="gap: 2px 8px"
        >
            <span
                class="inline-flex flex-none items-center gap-[3px]"
                :title="$t('Pending trend over the last hour')"
            >
                <component :is="trend.icon" :size="12" />{{ trend.label }}
            </span>
            <span v-if="measured">
                {{
                    $tChoice(':count node|:count nodes', environment.nodeCount)
                }}
                ·
                {{
                    $tChoice(
                        ':count worker|:count workers',
                        environment.workers,
                    )
                }}
            </span>
            <span
                class="ml-auto flex-none"
                :title="failedWindowNote(environment.failedWindowMinutes)"
            >
                <span
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
                {{ failedWindowShort(environment.failedWindowMinutes) }}
            </span>
        </span>
    </Link>
</template>

<style scoped>
.tile {
    position: relative;
    display: block;
    width: 100%;
    text-align: left;
    padding: var(--nc-space-3) var(--nc-space-3) var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    color: inherit;
    text-decoration: none;
}

.tile:hover {
    box-shadow: var(--nc-shadow-md) !important;
}

.state {
    display: inline-flex;
    flex: none;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 500;
}

.reading {
    font-size: 22px;
    line-height: 1;
    font-weight: 500;
}
</style>
