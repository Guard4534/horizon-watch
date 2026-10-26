<script setup lang="ts">
import { PhWarningCircle } from '@phosphor-icons/vue';

defineProps<{
    title: string;
}>();
</script>

<template>
    <div class="warning-notice" role="status">
        <PhWarningCircle :size="16" class="icon mt-[2px] flex-none" />
        <div class="min-w-0">
            <div class="headline">{{ title }}</div>
            <div class="detail"><slot /></div>
        </div>
    </div>
</template>

<style scoped>
.warning-notice {
    display: flex;
    align-items: flex-start;
    gap: var(--nc-space-2);
    padding: var(--nc-space-3);
    border: 1px solid color-mix(in srgb, var(--st-warn) 45%, transparent);
    border-radius: var(--nc-radius-md);
    background: color-mix(in srgb, var(--st-warn) 7%, transparent);
}

.icon {
    color: var(--st-warn);
}

.headline {
    font-size: 13px;
    line-height: 1.4;
    color: var(--nc-text);
}

.detail {
    margin-top: 3px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--nc-neutral-400);
}

.detail :deep(a) {
    color: var(--nc-text);
    text-decoration: underline;
    text-underline-offset: 2px;
}
</style>
