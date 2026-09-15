<script setup lang="ts">
const { size = 16, withName = true } = defineProps<{
    size?: number;
    withName?: boolean;
}>();
</script>

<template>
    <span class="inline-flex items-center gap-[9px]">
        <span
            class="flex-none"
            :style="{
                width: `${size}px`,
                height: `${size}px`,
                borderRadius: '5px',
                background: 'var(--nc-accent)',
                boxShadow: 'inset 0 0 0 3px var(--nc-bg), 0 0 14px color-mix(in srgb, var(--nc-accent) 45%, transparent)',
            }"
        />
        <span v-if="withName" style="font-size: 15px; font-weight: 500; letter-spacing: -0.01em">Horizon Watch</span>
    </span>
</template>
