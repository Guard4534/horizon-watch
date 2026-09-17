<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhMinus, PhTrendDown, PhTrendUp } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { failedWindowNote } from '@/lib/failedWindow';
import {
    envColor,
    formatCount,
    formatWait,
    pendingColor,
    statusColor,
    waitColor,
} from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

const { environment, failedPerHourThreshold } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
    failedPerHourThreshold: number;
}>();

const slug = useTeamSlug();

// The left bar is the environment's own colour, so trouble cannot be a
// coloured border too: it is the lamp, a tinted ground and a soft halo.
// A row with no status yet is not trouble, only not started.
const troubled = computed(
    () => environment.status !== null && environment.status !== 'active',
);
const tint = computed(() =>
    environment.status === null
        ? 'var(--nc-neutral-600)'
        : statusColor(environment.status),
);

const tileStyle = computed(() =>
    troubled.value
        ? {
              background: `color-mix(in srgb, ${tint.value} 15%, var(--nc-surface))`,
              boxShadow: `var(--nc-shadow-sm), 0 0 0 4px color-mix(in srgb, ${tint.value} 18%, transparent)`,
          }
        : {
              background: 'var(--nc-surface)',
              boxShadow: 'var(--nc-shadow-sm)',
              opacity: 0.82,
          },
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
    environment.failedLastHour > failedPerHourThreshold
        ? 'var(--st-warn)'
        : 'var(--nc-neutral-600)',
);

// What the footer says instead of nodes and workers when the numbers
// above are not a current reading.
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
        :href="
            showEnvironment({ current_team: slug, environment: environment.id })
        "
        class="tile"
        :style="tileStyle"
    >
        <span
            class="absolute top-0 bottom-0 left-0 w-[4px]"
            :style="{ background: envColor(environment.color) }"
        />
        <span class="flex items-center gap-[7px]">
            <span class="min-w-0 flex-1">
                <EnvPill :name="environment.name" :color="environment.color" />
            </span>
            <StatusLamp :status="environment.status" glow />
        </span>
        <span class="nc-num mt-[11px] flex items-baseline gap-[5px]">
            <span
                style="font-size: 19px; line-height: 1"
                :style="{ color: pendingColor(environment.pending) }"
                >{{ formatCount(environment.pending) }}</span
            >
            <span
                style="
                    font-size: 9px;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                    color: var(--nc-neutral-600);
                "
                >pending</span
            >
            <span
                class="ml-auto"
                style="font-size: 11px"
                :style="{ color: waitColor(environment.maxWaitSeconds) }"
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
            class="nc-num mt-[5px] flex items-center gap-2"
            style="font-size: 10px; color: var(--nc-neutral-600)"
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
                    · {{ environment.workers }} workers
                </template>
            </span>
            <span
                class="ml-auto flex-none"
                :style="{ color: failedColor }"
                :title="failedWindowNote(environment.failedWindowMinutes)"
            >
                {{ environment.failedInWindow }} failed
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
