<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { severityColor } from '@/lib/alerts';

const {
    queue,
    thresholds,
    quiet = false,
} = defineProps<{
    queue: Pick<
        App.Data.Monitoring.QueueData,
        'waitOverThreshold' | 'withoutWorkers'
    >;
    thresholds: App.Data.Monitoring.EnvironmentData['thresholds'];
    quiet?: boolean;
}>();

function flag(metric: App.Enums.AlertRuleMetric, label: string) {
    const threshold = thresholds[metric] as
        | App.Data.Monitoring.ThresholdData
        | undefined;
    const tone = threshold
        ? severityColor(threshold.severity)
        : 'var(--nc-neutral-400)';

    return {
        metric,
        label,
        style: {
            color: tone,
            background: `color-mix(in srgb, ${tone} 16%, transparent)`,
        },
    };
}

const flags = computed(() => [
    ...(queue.waitOverThreshold
        ? [flag('queue.max_wait', trans('wait over threshold'))]
        : []),
    ...(queue.withoutWorkers
        ? [flag('workers.missing', trans('no worker'))]
        : []),
]);
</script>

<template>
    <span v-if="flags.length" class="inline-flex flex-wrap gap-1">
        <span
            v-for="item in flags"
            :key="item.metric"
            class="nc-tag nc-tag-sm"
            :style="item.style"
            >{{ item.label }}</span
        >
    </span>
    <span v-else-if="!quiet" class="nc-tone-faint">—</span>
</template>
