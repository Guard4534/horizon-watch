<script setup lang="ts">
import { computed } from 'vue';
import { envColor } from '@/lib/monitoring';

const { color, size = 8 } = defineProps<{
    color: App.Enums.EnvironmentColor;
    size?: number;
}>();

const style = computed(() => ({
    width: `${size}px`,
    height: `${size}px`,
    borderRadius: size > 8 ? '3px' : '2px',
    background: envColor(color),
}));
</script>

<template>
    <span class="inline-block flex-none" :style="style" />
</template>
