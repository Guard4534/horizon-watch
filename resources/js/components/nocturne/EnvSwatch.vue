<script setup lang="ts">
import { computed } from 'vue';
import { envTypeColor } from '@/lib/monitoring';

const { type, size = 8 } = defineProps<{
    type: App.Enums.EnvironmentType;
    size?: number;
}>();

const style = computed(() => ({
    width: `${size}px`,
    height: `${size}px`,
    borderRadius: size > 8 ? '3px' : '2px',
    background: envTypeColor(type),
}));
</script>

<template>
    <span class="inline-block flex-none" :style="style" />
</template>
