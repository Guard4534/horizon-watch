<script setup lang="ts">
import { PhShieldCheck } from '@phosphor-icons/vue';
import { isProductionType } from '@/lib/environments';
import { envTypeColor } from '@/lib/monitoring';

const model = defineModel<App.Enums.EnvironmentType>({ required: true });

defineProps<{
    types: { value: string; label: string }[];
    name: string;
}>();

const asType = (value: string) => value as App.Enums.EnvironmentType;
</script>

<template>
    <div class="flex flex-wrap items-center" style="gap: 6px" role="radiogroup">
        <label v-for="option in types" :key="option.value" class="option">
            <input
                v-model="model"
                type="radio"
                :name="name"
                :value="option.value"
            />
            <span class="chip">
                <span
                    class="swatch"
                    :style="{ background: envTypeColor(asType(option.value)) }"
                />
                <PhShieldCheck
                    v-if="isProductionType(asType(option.value))"
                    :size="12"
                    weight="bold"
                    aria-hidden="true"
                />
                {{ option.label }}
            </span>
        </label>
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
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: var(--nc-radius-sm);
    border: 1px solid var(--nc-divider);
}

.swatch {
    width: 8px;
    height: 8px;
    flex: none;
    border-radius: 2px;
}

.option:hover .chip {
    border-color: color-mix(in srgb, var(--nc-text) 45%, transparent);
}

.option:has(input:checked) .chip {
    border-color: var(--nc-accent);
    color: var(--nc-accent-200);
    background: color-mix(in srgb, var(--nc-accent) 12%, transparent);
}

.option:has(input:focus-visible) {
    outline: 2px solid var(--nc-accent);
    outline-offset: 2px;
}
</style>
