<script setup lang="ts">
const { color = 'var(--nc-text)', compact = false } = defineProps<{
    label: string;
    value: string;
    note?: string;
    color?: string;
    compact?: boolean;
}>();
</script>

<template>
    <div class="nc-card kpi" :class="{ 'kpi-compact': compact }">
        <template v-if="compact">
            <div class="nc-micro nc-tone-faint truncate">{{ label }}</div>
            <div class="nc-num value-compact" :style="{ color }">
                {{ value }}
            </div>
        </template>
        <template v-else>
            <div class="nc-label">{{ label }}</div>
            <div class="nc-num value" :style="{ color }">{{ value }}</div>
            <div v-if="note" class="nc-t-xs nc-tone-faint">{{ note }}</div>
        </template>
    </div>
</template>

<style scoped>
.kpi {
    position: relative;
}

.kpi-compact {
    min-width: 0;
    padding: var(--nc-space-2) var(--nc-space-3);
}

.value {
    font-size: 34px;
    font-weight: 500;
    line-height: 1.05;
    letter-spacing: -0.02em;
    margin-top: 4px;
}

.value-compact {
    margin-top: 2px;
    font-size: 19px;
    line-height: 1.15;
}
</style>
