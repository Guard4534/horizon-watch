<script setup lang="ts">
defineProps<{
    title?: string;
}>();
</script>

<template>
    <section class="nc-card">
        <div
            v-if="title || $slots.actions"
            class="mb-[var(--nc-space-3)] flex flex-wrap items-baseline gap-2"
        >
            <span v-if="title" style="font-size: 14px">{{ title }}</span>
            <div class="ml-auto flex items-baseline gap-2">
                <slot name="actions" />
            </div>
        </div>
        <slot />
    </section>
</template>
