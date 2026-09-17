<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { formatAge } from '@/components/monitoring/environment/readings';
import { statusColor, statusLabel } from '@/lib/monitoring';

const { node } = defineProps<{
    node: App.Data.Monitoring.NodeData;
}>();

const color = computed(() => statusColor(node.status));

const background = computed(() =>
    node.status === 'active'
        ? 'var(--nc-bg)'
        : `color-mix(in srgb, ${color.value} 8%, var(--nc-bg))`,
);

const figures = computed(() => [
    { key: 'workers', label: trans('workers'), value: node.workers },
    {
        key: 'supervisors',
        label: trans('supervisors'),
        value: node.supervisorCount,
    },
    { key: 'queues', label: trans('queues'), value: node.queueCount },
]);
</script>

<template>
    <div
        class="relative overflow-hidden"
        style="
            padding: var(--nc-space-3);
            border-radius: var(--nc-radius-md);
            box-shadow: var(--nc-shadow-sm);
        "
        :style="{ background }"
    >
        <div class="flex items-center gap-[7px]">
            <StatusLamp :status="node.status" :size="8" />
            <span
                class="min-w-0 truncate"
                style="font-size: 12px; letter-spacing: 0.01em"
                :title="node.hostname"
                >{{ node.hostname }}</span
            >
            <span
                class="ml-auto flex-none"
                style="font-size: 10px"
                :style="{ color }"
                >{{ statusLabel(node.status) }}</span
            >
        </div>
        <div
            class="nc-num mt-[var(--nc-space-3)] flex flex-wrap"
            style="gap: var(--nc-space-2) var(--nc-space-4)"
        >
            <div v-for="figure in figures" :key="figure.key">
                <div style="font-size: 15px">{{ figure.value }}</div>
                <div
                    style="
                        font-size: 9px;
                        letter-spacing: 0.08em;
                        text-transform: uppercase;
                        color: var(--nc-neutral-600);
                    "
                >
                    {{ figure.label }}
                </div>
            </div>
        </div>
        <div
            class="nc-num mt-[var(--nc-space-3)]"
            style="font-size: 11px; color: var(--nc-neutral-600)"
        >
            {{ $t('seen :time ago', { time: formatAge(node.seenSecondsAgo) }) }}
        </div>
    </div>
</template>
