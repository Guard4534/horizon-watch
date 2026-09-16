<script setup lang="ts">
import { envColor } from '@/lib/monitoring';

const model = defineModel<App.Enums.EnvironmentColor>({ required: true });

defineProps<{
    // The seven cases of EnvironmentColor, with their labels, straight from
    // the page prop: the palette is fixed server-side and never free-form.
    colors: App.Data.Pages.EnvironmentFormPageData['colors'];
    // Radio inputs are grouped by name, and the wizard renders one picker per
    // environment row, so every picker needs a name of its own.
    name: string;
}>();

// The prop carries plain strings (the enum's backing values), which is what
// envColor() maps to the --env-* token.
const swatch = (value: string) => envColor(value as App.Enums.EnvironmentColor);
</script>

<template>
    <div class="flex flex-wrap" style="gap: var(--nc-space-2)">
        <label v-for="color in colors" :key="color.value" class="option">
            <input
                v-model="model"
                type="radio"
                :name="name"
                :value="color.value"
            />
            <span class="chip" :style="{ background: swatch(color.value) }" />
            {{ color.label }}
        </label>
    </div>
</template>

<style scoped>
.option {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 12px;
    padding: 5px 9px;
    border: 1px solid var(--nc-divider);
    border-radius: var(--nc-radius-md);
}

.option input {
    position: absolute;
    width: 0;
    height: 0;
    opacity: 0;
}

.chip {
    width: 10px;
    height: 10px;
    border-radius: 2px;
}

.option:not(:has(input:checked)):hover {
    background: color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.option:has(input:checked) {
    border-color: var(--nc-accent);
    color: var(--nc-accent);
}

.option:has(input:focus-visible) {
    outline: 2px solid var(--nc-accent);
    outline-offset: 2px;
}
</style>
