<script setup lang="ts">
const { color = 'var(--nc-text)' } = defineProps<{
    label: string;
    value: string;
    note: string;
    color?: string;
}>();
</script>

<template>
    <div
        style="
            padding: var(--nc-space-3);
            border-radius: var(--nc-radius-md);
            background: var(--nc-surface);
            box-shadow: var(--nc-shadow-sm);
        "
    >
        <div class="nc-label">{{ label }}</div>
        <div
            class="nc-num"
            style="font-size: 21px; line-height: 1.2; margin-top: 4px"
            :style="{ color }"
        >
            {{ value }}
        </div>
        <div style="font-size: 11px; color: var(--nc-neutral-600)">
            {{ note }}
        </div>
    </div>
</template>
