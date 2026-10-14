<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const { size = 16, withName = true } = defineProps<{
    size?: number;
    withName?: boolean;
}>();

const page = usePage();
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
                boxShadow:
                    'inset 0 0 0 3px var(--nc-bg), 0 0 14px color-mix(in srgb, var(--nc-accent) 45%, transparent)',
            }"
        />
        <span
            v-if="withName"
            style="font-size: 15px; font-weight: 500; letter-spacing: -0.01em"
            >{{ page.props.name }}</span
        >
    </span>
</template>
