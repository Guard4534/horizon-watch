<script setup lang="ts">
import { computed } from 'vue';
import { getInitials } from '@/lib/initials';

const { name, size = 26 } = defineProps<{
    name: string;
    size?: number;
}>();

const initials = computed(() => getInitials(name));
</script>

<template>
    <span
        aria-hidden="true"
        class="grid flex-none place-items-center rounded-full"
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            fontSize: size >= 36 ? '13px' : '10px',
            background: 'var(--nc-neutral-800)',
            color: 'var(--nc-neutral-200)',
        }"
        >{{ initials }}</span
    >
</template>
