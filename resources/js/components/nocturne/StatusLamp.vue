<script setup lang="ts">
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { statusColor, statusLabel } from '@/lib/monitoring';

const {
    status,
    size = 9,
    glow = false,
} = defineProps<{
    status: App.Enums.EnvironmentStatus | null;
    size?: number;
    glow?: boolean;
}>();

const troubled = computed(() => status !== null && status !== 'active');

const label = computed(() =>
    status === null ? trans('No reading yet') : statusLabel(status),
);

const style = computed(() => {
    const color =
        status === null ? 'var(--nc-neutral-600)' : statusColor(status);
    const px = glow && troubled.value ? 11 : size;

    return {
        width: `${px}px`,
        height: `${px}px`,
        background: color,
        boxShadow: glow && troubled.value ? `0 0 10px ${color}` : undefined,
    };
});
</script>

<template>
    <span
        class="inline-block flex-none rounded-full"
        :class="{ 'nc-pulse': troubled }"
        :style="style"
        role="img"
        :aria-label="label"
        :title="label"
    />
</template>
