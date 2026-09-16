<script setup lang="ts">
import { PhCheck } from '@phosphor-icons/vue';

// Labels arrive already translated, like KpiCard's and EmptyState's, so
// TranslationsTest still sees a literal $t() call site at the caller.
defineProps<{
    labels: string[];
    current: number;
}>();

// Only backwards: a later step may not have been filled in yet, and the
// wizard's own buttons are what validate a step before leaving it.
const emit = defineEmits<{ select: [step: number] }>();
</script>

<template>
    <ol class="flex flex-wrap items-center" style="gap: var(--nc-space-3)">
        <li v-for="(label, index) in labels" :key="index">
            <button
                type="button"
                class="step"
                :class="{
                    'step-current': index + 1 === current,
                    'step-done': index + 1 < current,
                }"
                :disabled="index + 1 >= current"
                :aria-current="index + 1 === current ? 'step' : undefined"
                @click="emit('select', index + 1)"
            >
                <span class="badge">
                    <PhCheck v-if="index + 1 < current" :size="11" />
                    <template v-else>{{ index + 1 }}</template>
                </span>
                {{ label }}
            </button>
        </li>
    </ol>
</template>

<style scoped>
.step {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font: inherit;
    font-size: 12px;
    color: var(--nc-neutral-500);
    background: transparent;
    border: 0;
    padding: 0;
}

.step:disabled {
    cursor: default;
}

.step-done:not(:disabled) {
    cursor: pointer;
    color: var(--nc-neutral-300);
}

.step-current {
    color: var(--nc-accent);
}

.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 99px;
    font-size: 11px;
    border: 1px solid currentColor;
}

.step-current .badge {
    background: color-mix(in srgb, var(--nc-accent) 16%, transparent);
}

.step:focus-visible {
    outline: 2px solid var(--nc-accent);
    outline-offset: 3px;
}
</style>
