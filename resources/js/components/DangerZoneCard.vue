<script setup lang="ts">
import { PhWarningOctagon } from '@phosphor-icons/vue';

defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <section class="nc-card danger-zone">
        <div
            class="nc-tone-down flex items-center gap-2"
            style="font-size: 14px"
        >
            <PhWarningOctagon :size="15" class="flex-none" />
            {{ title }}
        </div>
        <p
            v-if="description"
            class="nc-t-xs nc-tone-soft mt-[var(--nc-space-2)]"
        >
            {{ description }}
        </p>
        <div class="mt-[var(--nc-space-3)]">
            <slot />
        </div>
    </section>
</template>

<style scoped>
.danger-zone {
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--st-down) 45%, transparent);
}
</style>
