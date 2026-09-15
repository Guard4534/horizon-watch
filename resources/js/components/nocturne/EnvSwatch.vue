<script setup lang="ts">
import { computed } from 'vue';
import { envColor } from '@/lib/monitoring';
import type { EnvironmentColor } from '@/types/monitoring';

const { color, shape = 'square', size = 10 } = defineProps<{
    color: EnvironmentColor;
    shape?: 'square' | 'bar' | 'edge';
    size?: number;
}>();

const style = computed(() => {
    const background = envColor(color);

    switch (shape) {
        case 'bar':
            return { width: '3px', height: `${size}px`, borderRadius: '2px', background };
        case 'edge':
            return { position: 'absolute' as const, left: 0, top: 0, bottom: 0, width: `${size}px`, background };
        default:
            return { width: `${size}px`, height: `${size}px`, borderRadius: size > 8 ? '3px' : '2px', background };
    }
});
</script>

<template>
    <span class="inline-block flex-none" :style="style" />
</template>
