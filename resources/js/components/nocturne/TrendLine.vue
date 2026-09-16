<script setup lang="ts">
import { computed } from 'vue';

const {
    values,
    width,
    height,
    color = 'var(--nc-accent)',
    fill = false,
} = defineProps<{
    values: number[];
    width: number;
    height: number;
    color?: string;
    fill?: boolean;
}>();

const line = computed(() => {
    const max = Math.max(...values, 1);
    const step = values.length > 1 ? width / (values.length - 1) : 0;

    return values
        .map((value, index) => {
            const x = (index * step).toFixed(1);
            const y = (height - (value / max) * (height - 4) - 2).toFixed(1);

            return `${index ? 'L' : 'M'}${x} ${y}`;
        })
        .join(' ');
});

const area = computed(
    () => `${line.value} L ${width} ${height} L 0 ${height} Z`,
);
</script>

<template>
    <svg
        :viewBox="`0 0 ${width} ${height}`"
        width="100%"
        :height="height"
        preserveAspectRatio="none"
        class="block"
    >
        <path
            v-if="fill"
            :d="area"
            :fill="color"
            opacity="0.12"
            stroke="none"
        />
        <path
            :d="line"
            fill="none"
            :stroke="color"
            stroke-width="1.5"
            stroke-linejoin="round"
        />
    </svg>
</template>
