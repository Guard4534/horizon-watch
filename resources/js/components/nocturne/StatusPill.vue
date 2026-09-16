<script setup lang="ts">
import { computed } from 'vue';
import { statusColor, statusLabel } from '@/lib/monitoring';
import type { EnvironmentStatus } from '@/types/monitoring';

const { status } = defineProps<{
    status: EnvironmentStatus;
}>();

const color = computed(() => statusColor(status));
</script>

<template>
    <span
        class="inline-flex items-center gap-[6px]"
        style="
            font-size: 12px;
            border-radius: var(--nc-radius-sm);
            padding: 3px 9px;
        "
        :style="{ color, border: `1px solid ${color}` }"
    >
        <span class="size-[6px] rounded-full" :style="{ background: color }" />
        {{ statusLabel(status) }}
    </span>
</template>
