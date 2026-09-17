<script setup lang="ts">
import { computed } from 'vue';

// Labels arrive already translated, like KpiCard's and EmptyState's, so
// TranslationsTest still sees a literal $t() call site at the caller.
const { labels, current } = defineProps<{
    labels: string[];
    current: number;
}>();

// Only backwards: a later step may not have been filled in yet, and the
// wizard's own buttons are what validate a step before leaving it.
const emit = defineEmits<{ select: [step: number] }>();

const currentLabel = computed(() => labels[current - 1] ?? '');
</script>

<template>
    <nav class="flex flex-col" style="gap: 8px">
        <div style="font-size: 11px; color: var(--nc-neutral-500)">
            {{
                $t('Step :current of :total', {
                    current: String(current),
                    total: String(labels.length),
                })
            }}
            · {{ currentLabel }}
        </div>
        <ol class="flex" style="gap: 4px">
            <li v-for="(label, index) in labels" :key="index" class="flex-1">
                <button
                    type="button"
                    class="bar"
                    :class="{ 'bar-reached': index + 1 <= current }"
                    :disabled="index + 1 >= current"
                    :aria-label="label"
                    :title="label"
                    :aria-current="index + 1 === current ? 'step' : undefined"
                    @click="emit('select', index + 1)"
                />
            </li>
        </ol>
    </nav>
</template>

<style scoped>
.bar {
    display: block;
    width: 100%;
    height: 10px;
    padding: 4px 0;
    border: 0;
    background: transparent;
    background-clip: content-box;
    background-color: var(--nc-neutral-800);
    border-radius: 2px;
}

.bar-reached {
    background-color: var(--nc-accent);
}

.bar:not(:disabled) {
    cursor: pointer;
}

.bar:not(:disabled):hover {
    background-color: color-mix(in srgb, var(--nc-accent) 70%, transparent);
}

.bar:focus-visible {
    outline: 2px solid var(--nc-accent);
    outline-offset: 2px;
}
</style>
