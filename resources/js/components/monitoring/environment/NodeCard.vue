<script setup lang="ts">
import { computed } from 'vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { statusColor, statusLabel } from '@/lib/monitoring';

const { node } = defineProps<{
    node: App.Data.Monitoring.NodeData;
}>();

const color = computed(() => statusColor(node.status));
const heartbeat = computed(() =>
    node.lastHeartbeatSecondsAgo >= 60
        ? `${Math.round(node.lastHeartbeatSecondsAgo / 60)} min`
        : `${node.lastHeartbeatSecondsAgo} s`,
);
</script>

<template>
    <div
        class="relative overflow-hidden"
        style="
            padding: var(--nc-space-3);
            border-radius: var(--nc-radius-md);
            background: var(--nc-bg);
        "
        :style="{
            boxShadow:
                node.status === 'active'
                    ? 'var(--nc-shadow-sm)'
                    : `0 0 0 1px ${color}`,
        }"
    >
        <div class="flex items-center gap-[7px]">
            <StatusLamp :status="node.status" :size="8" />
            <span
                class="min-w-0 truncate"
                style="font-size: 12px; letter-spacing: 0.01em"
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
            class="nc-num mt-[var(--nc-space-3)] grid grid-cols-3"
            style="gap: var(--nc-space-2)"
        >
            <div>
                <div style="font-size: 15px">{{ node.workers }}</div>
                <div
                    class="nc-label"
                    style="font-size: 9px; letter-spacing: 0.08em"
                >
                    workers
                </div>
            </div>
            <div>
                <div style="font-size: 15px">{{ node.jobsPerMinute }}</div>
                <div
                    class="nc-label"
                    style="font-size: 9px; letter-spacing: 0.08em"
                >
                    jobs/min
                </div>
            </div>
            <div>
                <div
                    style="font-size: 15px"
                    :style="{
                        color:
                            node.memoryMb > 320
                                ? 'var(--st-warn)'
                                : 'var(--nc-text)',
                    }"
                >
                    {{ node.memoryMb }} MB
                </div>
                <div
                    class="nc-label"
                    style="font-size: 9px; letter-spacing: 0.08em"
                >
                    memory
                </div>
            </div>
        </div>
        <div
            class="mt-[var(--nc-space-3)] flex gap-[6px]"
            style="font-size: 11px; color: var(--nc-neutral-600)"
        >
            <span class="min-w-0 truncate"
                >{{ node.supervisorCount }} supervisor ·
                {{ node.queueCount }} queue</span
            >
            <span class="ml-auto flex-none">{{
                $t(':time ago', { time: heartbeat })
            }}</span>
        </div>
    </div>
</template>
