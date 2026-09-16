<script setup lang="ts">
import LocaleSwitch from '@/components/LocaleSwitch.vue';
import BrandMark from '@/components/nocturne/BrandMark.vue';

const {
    title = '',
    description = '',
    kicker = '',
} = defineProps<{
    title?: string;
    description?: string;
    kicker?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-[var(--nc-space-6)] p-6"
        style="background: var(--nc-bg)"
    >
        <BrandMark />

        <section
            class="nc-card w-full max-w-[380px]"
            style="padding: var(--nc-space-6)"
        >
            <div
                v-if="kicker"
                class="nc-kicker"
                style="margin-bottom: var(--nc-space-2)"
            >
                {{ $t(kicker) }}
            </div>
            <h3
                style="
                    font-size: 25px;
                    line-height: 1.12;
                    margin: 0 0 var(--nc-space-2);
                "
            >
                {{ $t(title) }}
            </h3>
            <p
                v-if="description"
                style="
                    font-size: 13px;
                    color: var(--nc-neutral-400);
                    margin: 0 0 var(--nc-space-4);
                "
            >
                {{ $t(description) }}
            </p>
            <slot />
        </section>

        <LocaleSwitch name="auth-locale" />
    </div>
</template>
