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
            <span
                class="absolute top-0 bottom-0 left-0 w-[3px]"
                :style="{ background: color }"
            />
            <div class="nc-label nc-tone-muted flex items-center gap-[7px]">
                <span
                    class="size-[7px] flex-none rounded-full"
                    :style="{ background: color }"
                />
                {{ label }}
            </div>
            <div class="nc-num value" :style="{ color }">{{ value }}</div>
            <div v-if="note" class="nc-t-2xs nc-tone-muted">{{ note }}</div>
        </template>
    </div>
</template>

<style scoped>
.kpi {
    position: relative;
    overflow: hidden;
}

.kpi-compact {
    min-width: 0;
    padding: var(--nc-space-2) var(--nc-space-3);
}

.value {
    font-size: 38px;
    line-height: 1.05;
    margin-top: 6px;
}

.value-compact {
    margin-top: 2px;
    font-size: 19px;
    line-height: 1.15;
}
</style>
