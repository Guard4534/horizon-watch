<script setup lang="ts">
defineProps<{
    label: string;
    value: string;
    note: string;
    color: string;
}>();
</script>

<template>
    <div
        class="relative overflow-hidden"
        style="padding: var(--nc-space-4); border-radius: var(--nc-radius-md); background: var(--nc-surface); box-shadow: var(--nc-shadow-sm)"
    >
        <span class="absolute top-0 bottom-0 left-0 w-[3px]" :style="{ background: color }" />
        <div class="nc-label flex items-center gap-[7px]" style="color: var(--nc-neutral-500)">
            <span class="size-[7px] flex-none rounded-full" :style="{ background: color }" />
            {{ label }}
        </div>
        <div class="nc-num" style="font-size: 38px; line-height: 1.05; margin-top: 6px" :style="{ color }">
            {{ value }}
        </div>
        <div style="font-size: 11px; color: var(--nc-neutral-500)">{{ note }}</div>
    </div>
</template>
