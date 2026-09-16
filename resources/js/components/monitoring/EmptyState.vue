<script setup lang="ts">
import type { Component } from 'vue';

// The mockup never drew an empty state: this is deliberately the soberest
// Nocturne card there is — one column, centred, no illustration. Strings
// arrive already translated, like KpiCard's, so TranslationsTest still sees
// a literal $t() call site at every caller.
defineProps<{
    title: string;
    body: string;
    kicker?: string;
    icon?: Component;
}>();
</script>

<template>
    <section
        class="nc-card mx-auto flex w-full flex-col items-center text-center"
        style="
            max-width: 430px;
            padding: var(--nc-space-6);
            gap: var(--nc-space-2);
        "
    >
        <div
            v-if="icon"
            class="flex items-center justify-center"
            style="
                width: 38px;
                height: 38px;
                border-radius: 99px;
                margin-bottom: var(--nc-space-1);
                color: var(--nc-accent);
                background: color-mix(
                    in srgb,
                    var(--nc-accent) 12%,
                    transparent
                );
            "
        >
            <component :is="icon" :size="18" />
        </div>

        <div v-if="kicker" class="nc-kicker">{{ kicker }}</div>

        <div style="font-size: 17px; line-height: 1.25">{{ title }}</div>

        <p
            style="
                font-size: 13px;
                line-height: 1.5;
                color: var(--nc-neutral-400);
            "
        >
            {{ body }}
        </p>

        <!-- No wrapper: a caller whose action is itself conditional still
             passes a slot, and a div around an empty one would leave a gap
             under the text where no button ever appears. -->
        <slot />
    </section>
</template>
