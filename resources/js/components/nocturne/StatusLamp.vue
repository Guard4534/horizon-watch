<script setup lang="ts">
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { statusColor, statusLabel } from '@/lib/monitoring';

const {
    status,
    size = 8,
    label,
} = defineProps<{
    status: App.Enums.EnvironmentStatus | null;
    size?: number;
    label?: string;
}>();

const text = computed(
    () =>
        label ??
        (status === null ? trans('No reading yet') : statusLabel(status)),
);

const style = computed(() => ({
    width: `${size}px`,
    height: `${size}px`,
    background: status === null ? 'var(--nc-neutral-600)' : statusColor(status),
}));
</script>

<template>
    <span
        class="inline-block flex-none rounded-full"
        :style="style"
        role="img"
        :aria-label="text"
        :title="text"
    />
</template>
