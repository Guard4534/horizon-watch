<script setup lang="ts">
import { computed } from 'vue';
import { envColor } from '@/lib/monitoring';

const model = defineModel<App.Enums.EnvironmentColor>({ required: true });

const { colors } = defineProps<{
    colors: App.Data.Pages.EnvironmentFormPageData['colors'];
    name: string;
}>();

const swatch = (value: string) => envColor(value as App.Enums.EnvironmentColor);

const selected = computed(
    () => colors.find((color) => color.value === model.value)?.label ?? '',
);
</script>

<template>
    <div class="flex flex-wrap items-center" style="gap: 6px">
        <label
            v-for="color in colors"
            :key="color.value"
            class="option"
            :title="color.label"
        >
            <input
                v-model="model"
                type="radio"
                :name="name"
                :value="color.value"
                :aria-label="color.label"
            />
            <span class="chip" :style="{ background: swatch(color.value) }" />
        </label>
        <span class="nc-t-xs nc-tone-soft ml-1">{{ selected }}</span>
    </div>
</template>

<style scoped>
.option {
    display: inline-flex;
    cursor: pointer;
    border-radius: var(--nc-radius-sm);
}

.option input {
    position: absolute;
    width: 0;
    height: 0;
    opacity: 0;
}

.chip {
    width: 24px;
    height: 24px;
    border-radius: var(--nc-radius-sm);
    border: 2px solid transparent;
}

.option:hover .chip {
    border-color: color-mix(in srgb, var(--nc-text) 45%, transparent);
}

.option:has(input:checked) .chip {
    border-color: var(--nc-text);
    box-shadow: 0 0 0 2px var(--nc-surface);
}

.option:has(input:focus-visible) {
    outline: 2px solid var(--nc-accent);
    outline-offset: 2px;
}
</style>
