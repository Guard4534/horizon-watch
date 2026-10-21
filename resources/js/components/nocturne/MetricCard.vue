<script setup lang="ts">
const { color = 'var(--nc-text)', size = 'default' } = defineProps<{
    label: string;
    value: string;
    note?: string;
    color?: string;
    size?: 'hero' | 'default' | 'compact';
}>();
</script>

<template>
    <div class="nc-card metric" :class="`metric-${size}`">
        <div v-if="size === 'compact'" class="nc-micro nc-tone-faint truncate">
            {{ label }}
        </div>
        <div v-else class="nc-label">{{ label }}</div>
        <div class="nc-num metric-value" :style="{ color }">{{ value }}</div>
        <div
            v-if="note && size !== 'compact'"
            class="nc-tone-faint"
            :class="size === 'hero' ? 'nc-t-xs' : 'nc-t-2xs'"
        >
            {{ note }}
        </div>
    </div>
</template>

<style scoped>
.metric {
    min-width: 0;
}

.metric-hero {
    padding: var(--nc-space-4);
}

.metric-default {
    padding: var(--nc-space-3);
}

.metric-compact {
    padding: var(--nc-space-2) var(--nc-space-3);
}

.metric-value {
    margin-top: 4px;
    font-size: 21px;
    line-height: 1.2;
}

.metric-hero .metric-value {
    font-size: 34px;
    font-weight: 500;
    line-height: 1.05;
    letter-spacing: -0.02em;
}

.metric-compact .metric-value {
    margin-top: 2px;
    font-size: 18px;
    line-height: 1.15;
}
</style>
