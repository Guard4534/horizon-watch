<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const { size = 22, withName = true } = defineProps<{
    size?: number;
    withName?: boolean;
}>();

const page = usePage();
</script>

<template>
    <span class="inline-flex items-center gap-[10px]">
        <svg
            class="flex-none"
            :width="size"
            :height="size"
            viewBox="0 0 32 32"
            aria-hidden="true"
        >
            <rect width="32" height="32" rx="8" fill="var(--nc-accent-800)" />
            <path d="M8.5 17.5a7.5 7.5 0 0 1 15 0z" fill="var(--nc-accent)" />
            <rect
                x="5"
                y="19.5"
                width="22"
                height="2.5"
                rx="1.25"
                fill="var(--nc-accent-200)"
            />
            <rect
                x="10"
                y="24"
                width="12"
                height="2"
                rx="1"
                fill="var(--nc-accent)"
                opacity="0.55"
            />
        </svg>
        <span
            v-if="withName"
            style="font-size: 15px; font-weight: 600; letter-spacing: -0.015em"
            >{{ page.props.name }}</span
        >
    </span>
</template>
