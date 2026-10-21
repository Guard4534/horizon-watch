<script setup lang="ts">
import { computed } from 'vue';
import { lampColor, lampLabel } from '@/components/nocturne/status';

const {
    status,
    label,
    tone,
    size = 8,
    decorative = false,
} = defineProps<{
    status: App.Enums.EnvironmentStatus | null;
    label?: string;
    tone?: string;
    size?: number;
    decorative?: boolean;
}>();

const text = computed(() => lampLabel(status, label));

const style = computed(() => ({
    width: `${size}px`,
    height: `${size}px`,
    background: lampColor(status, tone),
}));
</script>

<template>
    <span
        v-if="decorative"
        class="inline-block flex-none rounded-full"
        :style="style"
        aria-hidden="true"
    />
    <span
        v-else
        class="inline-block flex-none rounded-full"
        :style="style"
        role="img"
        :aria-label="text"
        :title="text"
    />
</template>
