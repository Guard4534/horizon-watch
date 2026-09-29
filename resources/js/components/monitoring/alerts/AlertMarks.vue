<script setup lang="ts">
import { PhBellSlash, PhCheckCircle, PhPauseCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { mutedText } from '@/lib/alerts';
import { formatElapsed } from '@/lib/monitoring';

const { alert } = defineProps<{
    alert: App.Data.Monitoring.AlertData;
}>();

const { locale } = useLocale();

const muted = computed(() => mutedText(alert, locale.value));
</script>

<template>
    <div
        v-if="muted || alert.handledBy || alert.collectionPaused"
        class="nc-t-2xs nc-tone-muted flex flex-col"
        style="gap: 2px"
    >
        <span v-if="muted" class="inline-flex items-center gap-[5px]">
            <PhBellSlash :size="12" class="flex-none" />
            <span class="min-w-0">{{ muted }}</span>
        </span>
        <span v-if="alert.handledBy" class="inline-flex items-center gap-[5px]">
            <PhCheckCircle
                :size="12"
                class="flex-none"
                style="color: var(--st-ok)"
            />
            <span class="min-w-0">{{
                $t('Handled by :name · :elapsed', {
                    name: alert.handledBy.name,
                    elapsed: formatElapsed(alert.handledMinutesAgo ?? 0),
                })
            }}</span>
        </span>
        <span
            v-if="alert.collectionPaused"
            class="inline-flex items-center gap-[5px]"
            :title="
                $t(
                    'Nothing is read from this environment while its collection is paused, so this alert stays as it was.',
                )
            "
        >
            <PhPauseCircle
                :size="12"
                class="flex-none"
                style="color: var(--st-off)"
            />
            <span class="min-w-0">{{ $t('Collection paused') }}</span>
        </span>
    </div>
</template>
