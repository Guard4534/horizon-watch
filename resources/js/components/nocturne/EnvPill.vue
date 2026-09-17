<script setup lang="ts">
import { computed } from 'vue';
import { envColor } from '@/lib/monitoring';

// The v2 environment name: text in the environment's colour on a 20% fill
// of the same colour. The name is the user's, never translated.
const {
    name,
    color,
    size = 12,
} = defineProps<{
    name: string;
    color: App.Enums.EnvironmentColor;
    size?: number;
}>();

const style = computed(() => {
    const background = envColor(color);

    return {
        color: background,
        background: `color-mix(in srgb, ${background} 20%, transparent)`,
        fontSize: `${size}px`,
        padding: size >= 12 ? '2px 8px' : '1px 7px',
    };
});
</script>

<template>
    <span class="env-pill" :style="style" :title="name">{{ name }}</span>
</template>

<style scoped>
.env-pill {
    display: inline-block;
    max-width: 100%;
    box-sizing: border-box;
    border-radius: var(--nc-radius-sm);
    letter-spacing: 0.01em;
    line-height: 1.45;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}
</style>
