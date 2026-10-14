<script setup lang="ts">
import LocaleSwitch from '@/components/LocaleSwitch.vue';
import BrandMark from '@/components/nocturne/BrandMark.vue';
import { Toaster } from '@/components/ui/sonner';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
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
            <h1
                style="font-size: 25px; line-height: 1.12"
                :style="{
                    margin: description
                        ? '0 0 var(--nc-space-2)'
                        : '0 0 var(--nc-space-4)',
                }"
            >
                {{ $t(title) }}
            </h1>
            <p
                v-if="description"
                class="nc-t-sm nc-tone-soft"
                style="margin: 0 0 var(--nc-space-4)"
            >
                {{ $t(description) }}
            </p>
            <slot />
        </section>

        <LocaleSwitch name="auth-locale" />
        <Toaster :container-aria-label="$t('Notifications')" />
    </div>
</template>
