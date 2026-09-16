<script setup lang="ts">
import { computed } from 'vue';
import { formatRule } from '@/lib/alertRules';
import { isDown, statusColor, statusIcon } from '@/lib/monitoring';

const { environment, alert } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
    alert: App.Data.Monitoring.AlertData | null;
}>();

const color = computed(() => statusColor(environment.status));
const icon = computed(() => statusIcon(environment.status));

const title = computed(() => {
    switch (environment.status) {
        case 'unreachable':
            return 'Endpoint unreachable for 6 minutes';
        case 'inactive':
            return 'Master supervisor inactive for 14 minutes';
        case 'paused':
            return 'Supervisor paused for 2h 11m';
        default:
            return 'Max wait above threshold on 3 queues';
    }
});

const body = computed(() =>
    isDown(environment.status)
        ? 'No active workers: jobs stay pending and the backlog grows by about 11 jobs per second.'
        : 'Jobs are being consumed, but the delay keeps growing past the configured threshold.',
);
</script>

<template>
    <div
        class="flex items-start gap-[11px]"
        style="
            padding: var(--nc-space-3) var(--nc-space-4);
            border-radius: var(--nc-radius-md);
        "
        :style="{
            border: `1px solid ${color}`,
            background: `color-mix(in srgb, ${color} 10%, transparent)`,
        }"
    >
        <component
            :is="icon"
            :size="18"
            class="mt-[2px] flex-none"
            :style="{ color }"
        />
        <div class="min-w-0">
            <div style="font-size: 14px">{{ $t(title) }}</div>
            <div
                class="mt-[3px]"
                style="font-size: 12px; color: var(--nc-neutral-300)"
            >
                {{ $t(body) }}
            </div>
            <div
                v-if="alert"
                class="mt-[5px]"
                style="font-size: 11px; color: var(--nc-neutral-500)"
            >
                {{ $t('Rule:') }}
                {{ formatRule(alert.metric, alert.threshold, alert.unit) }} ·
                {{ $t('notified over email and webhook') }}
            </div>
        </div>
    </div>
</template>
