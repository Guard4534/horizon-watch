<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { PhEye } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { useLastRefresh } from '@/composables/useLastRefresh';

const { title, subtitle, live } = defineProps<{
    title?: string;
    subtitle?: string;
    live: boolean;
}>();

const page = usePage();
const updatedAt = useLastRefresh();
const secondLine = computed(
    () => subtitle ?? page.props.currentTeam?.name ?? '',
);
</script>

<template>
    <header
        class="flex flex-wrap items-center"
        style="
            gap: var(--nc-space-4);
            padding: var(--nc-space-4) var(--nc-space-6);
            border-bottom: 1px solid var(--nc-divider);
        "
    >
        <div class="min-w-0">
            <h4 style="margin: 0; font-size: 20px; letter-spacing: -0.015em">
                {{ title ? $t(title) : '' }}
            </h4>
            <div style="font-size: 12px; color: var(--nc-neutral-500)">
                {{ subtitle ? $t(subtitle) : secondLine }}
            </div>
        </div>
        <div class="ml-auto flex items-center" style="gap: var(--nc-space-3)">
            <span
                v-if="live"
                class="nc-num inline-flex items-center gap-[6px]"
                style="font-size: 11px; color: var(--nc-neutral-400)"
            >
                <span
                    class="size-[6px] rounded-full"
                    style="
                        background: var(--st-ok);
                        animation: nc-pulse 2.4s ease-in-out infinite;
                    "
                />
                {{ $t('polling every 15s · updated') }} {{ updatedAt }}
            </span>
            <span
                class="inline-flex items-center gap-[6px]"
                style="
                    font-size: 11px;
                    color: var(--nc-neutral-500);
                    border: 1px solid var(--nc-divider);
                    border-radius: var(--nc-radius-sm);
                    padding: 3px 8px;
                "
            >
                <PhEye :size="13" />
                {{ $t('Read-only') }}
            </span>
        </div>
    </header>
</template>
