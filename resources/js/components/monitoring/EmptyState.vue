<script setup lang="ts">
import type { Component } from 'vue';

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

        <slot />
    </section>
</template>
