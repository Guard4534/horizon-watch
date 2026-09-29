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
    <div class="nc-card tile" :class="{ 'tile-compact': compact }">
        <div v-if="compact" class="nc-micro nc-tone-faint truncate">
            {{ label }}
        </div>
        <div v-else class="nc-label">{{ label }}</div>
        <div class="nc-num value" :style="{ color }">{{ value }}</div>
        <div v-if="note && !compact" class="nc-t-2xs nc-tone-faint">
            {{ note }}
        </div>
    </div>
</template>

<style scoped>
.tile {
    padding: var(--nc-space-3);
}

.tile-compact {
    padding: var(--nc-space-2) var(--nc-space-3);
}

.value {
    font-size: 21px;
    line-height: 1.2;
    margin-top: 4px;
}

.tile-compact .value {
    font-size: 17px;
    margin-top: 2px;
}
</style>
