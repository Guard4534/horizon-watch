<script setup lang="ts">
import { computed } from 'vue';
import {
    needsAttention,
    worstTone,
} from '@/components/monitoring/environment/readings';

const { count, environments } = defineProps<{
    count: number;
    environments: App.Data.Monitoring.EnvironmentData[];
}>();

const tone = computed(
    () =>
        worstTone(environments.filter(needsAttention)) ??
        'var(--nc-neutral-500)',
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
        $t('all good')
    }}</span>
</template>
