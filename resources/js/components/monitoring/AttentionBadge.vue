<script setup lang="ts">
import { computed } from 'vue';
import { statusColor } from '@/lib/monitoring';

const { count, worstStatus, paused } = defineProps<{
    count: number;
    worstStatus: App.Enums.EnvironmentStatus | null;
    paused: boolean;
}>();

const tone = computed(() =>
    worstStatus ? statusColor(worstStatus) : 'var(--nc-neutral-500)',
);
</script>

<template>
    <span
        v-if="count > 0"
        class="nc-tag nc-tag-sm flex-none"
        :style="{
            background: `color-mix(in srgb, ${tone} 18%, transparent)`,
            color: tone,
        }"
        >{{
            $tChoice(':count needs attention|:count need attention', count)
        }}</span
    >
    <span v-else class="nc-tag nc-tag-sm nc-tag-neutral flex-none">{{
        paused ? $t('Collection paused') : $t('all good')
    }}</span>
</template>
