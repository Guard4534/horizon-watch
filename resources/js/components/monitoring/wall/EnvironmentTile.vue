<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhMinus, PhTrendDown, PhTrendUp } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import {
    calmStyle,
    failedTone,
    needsAttention,
    pendingTone,
    troubledStyle,
} from '@/components/monitoring/environment/readings';
import { useEnvironmentHref } from '@/composables/useEnvironmentHref';
import { thresholdOf } from '@/lib/alertRules';
import { failedWindowNote } from '@/lib/failedWindow';
import {
    formatCount,
    formatWait,
    statusColor,
    waitColor,
} from '@/lib/monitoring';

const { environment } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
}>();

const environmentHref = useEnvironmentHref();

const troubled = computed(() => needsAttention(environment));
const tint = computed(() =>
    environment.status === null
        ? 'var(--nc-neutral-600)'
        : statusColor(environment.status),
);

const tileStyle = computed(() =>
    troubled.value ? troubledStyle(tint.value) : calmStyle(),
);

const trend = computed(() => {
    const percent = environment.trendPercent;

    if (percent === null || percent === 0) {
        return {
            icon: PhMinus,
            color: 'var(--nc-neutral-600)',
            label: percent === 0 ? '0%' : '',
        };
    }

    return percent > 0
        ? {
              icon: PhTrendUp,
              color: troubled.value ? tint.value : 'var(--st-warn)',
              label: `+${percent}%`,
          }
        : { icon: PhTrendDown, color: 'var(--st-ok)', label: `${percent}%` };
});

const failedColor = computed(() =>
    failedTone(
        environment.failedLastHour,
        thresholdOf(environment, 'jobs.failed_per_hour'),
    ),
);

const silence = computed<string | null>(() => {
    if (!environment.pollingEnabled) {
        return 'paused';
    }

    if (environment.stale) {
        return 'stale';
    }

    return environment.lastReadingAt === null ? 'waiting' : null;
});
</script>

<template>
    <Link
        :href="environmentHref(environment.id)"
        class="tile"
        :style="tileStyle"
    >
        <EnvSwatch :color="environment.color" shape="edge" :size="4" />
        <span class="flex items-center gap-[7px]">
            <span class="min-w-0 flex-1">
                <EnvPill :name="environment.name" :color="environment.color" />
            </span>
            <StatusLamp :status="environment.status" glow />
        </span>
        <span class="nc-num mt-[11px] flex items-baseline gap-[5px]">
            <span
                style="font-size: 19px; line-height: 1"
                :style="{
                    color: pendingTone(
                        environment.pending,
                        thresholdOf(environment, 'queue.pending'),
                    ),
                }"
                >{{ formatCount(environment.pending) }}</span
            >
            <span class="nc-micro nc-tone-faint">{{ $t('Pending') }}</span>
            <span
                class="nc-t-2xs ml-auto"
                :style="{
                    color: waitColor(
                        environment.maxWaitSeconds,
                        thresholdOf(environment, 'queue.max_wait'),
                    ),
                }"
                >{{ formatWait(environment.maxWaitSeconds) }}</span
            >
        </span>
        <span
            v-if="environment.trend.length > 1"
            class="mt-[6px] block"
            style="opacity: 0.85"
        >
            <TrendLine
                :values="environment.trend"
                :width="150"
                :height="18"
                :color="tint"
            />
        </span>
        <span
            class="nc-num nc-tone-faint mt-[5px] flex items-center gap-2"
            style="font-size: 10px"
        >
            <span
                class="inline-flex flex-none items-center gap-[3px]"
                :style="{ color: trend.color }"
                :title="$t('Pending trend over the last hour')"
            >
                <component :is="trend.icon" :size="12" />{{ trend.label }}
            </span>
            <span class="min-w-0 truncate">
                <template v-if="silence === 'paused'">{{
                    $t('Collection paused')
                }}</template>
                <template v-else-if="silence === 'stale'">{{
                    $t('Not updated')
                }}</template>
                <template v-else-if="silence === 'waiting'">{{
                    $t('No reading yet')
                }}</template>
                <template v-else>
                    {{
                        $tChoice(
                            ':count node|:count nodes',
                            environment.nodeCount,
                        )
                    }}
                    ·
                    {{
                        $t(':count workers', {
                            count: String(environment.workers),
                        })
                    }}
                </template>
            </span>
            <span
                class="ml-auto flex-none"
                :style="{ color: failedColor }"
                :title="failedWindowNote(environment.failedWindowMinutes)"
            >
                {{
                    $t(':count failed', {
                        count: String(environment.failedInWindow),
                    })
                }}
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
    padding: var(--nc-space-3) var(--nc-space-3) var(--nc-space-3)
        var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    color: inherit;
    text-decoration: none;
    overflow: hidden;
}

.tile:hover {
    box-shadow: var(--nc-shadow-md) !important;
}
</style>
