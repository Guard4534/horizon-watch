<script setup lang="ts">
import { computed } from 'vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { lampColor, lampLabel } from '@/components/nocturne/status';

const { status, label, tone } = defineProps<{
    status: App.Enums.EnvironmentStatus | null;
    label?: string;
    tone?: string;
}>();

const color = computed(() => lampColor(status, tone));
const text = computed(() => lampLabel(status, label));
</script>

<template>
    <span class="status-pill nc-t-xs" :style="{ color, borderColor: color }">
        <StatusLamp :status="status" :tone="tone" :size="6" decorative />
        {{ text }}
    </span>
</template>

<style scoped>
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 9px;
    border: 1px solid;
    border-radius: var(--nc-radius-sm);
}
</style>
