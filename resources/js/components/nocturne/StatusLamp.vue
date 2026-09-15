<script setup lang="ts">
import { computed } from 'vue';
import { statusColor } from '@/lib/monitoring';
import type { EnvironmentStatus } from '@/types/monitoring';

const { status, size = 9 } = defineProps<{
    status: EnvironmentStatus;
    size?: number;
}>();

const style = computed(() => ({
    width: `${size}px`,
    height: `${size}px`,
    background: statusColor(status),
}));
</script>

<template>
    <span
        class="inline-block flex-none rounded-full"
        :class="{ 'nc-pulse': status !== 'active' }"
        :style="style"
        :aria-label="status"
    />
</template>
