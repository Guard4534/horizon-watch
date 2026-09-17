<script setup lang="ts">
import { PhEnvelopeSimple, PhWebhooksLogo } from '@phosphor-icons/vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';

defineProps<{
    settings: App.Data.Monitoring.NotificationSettingsData;
}>();
</script>

<template>
    <SectionCard :title="$t('Delivery test')">
        <div
            style="
                font-size: 12px;
                color: var(--nc-neutral-400);
                line-height: 1.5;
                margin-bottom: var(--nc-space-3);
            "
        >
            {{
                $t(
                    'Sends a sample alert to the configured targets, so you know email and the webhook work before you actually need them.',
                )
            }}
        </div>
        <div
            class="flex flex-col"
            style="
                gap: var(--nc-space-2);
                font-size: 12px;
                color: var(--nc-neutral-500);
                margin-bottom: var(--nc-space-3);
            "
        >
            <div class="flex items-center gap-2">
                <PhEnvelopeSimple :size="14" class="flex-none" />
                <span class="min-w-0 truncate" style="color: var(--nc-text)">{{
                    settings.recipients.length
                        ? settings.recipients.join(', ')
                        : '—'
                }}</span>
            </div>
            <div class="flex items-center gap-2">
                <PhWebhooksLogo :size="14" class="flex-none" />
                <span
                    class="min-w-0 truncate"
                    style="color: var(--nc-text); letter-spacing: 0.01em"
                    >{{ settings.webhookUrl ?? '—' }}</span
                >
            </div>
        </div>
        <div class="flex flex-wrap" style="gap: var(--nc-space-2)">
            <button
                type="button"
                class="nc-btn nc-btn-secondary"
                style="font-size: 12px"
                disabled
                :title="$t('Available soon')"
            >
                <PhEnvelopeSimple :size="13" />
                {{ $t('Send a test email') }}
            </button>
            <button
                type="button"
                class="nc-btn nc-btn-secondary"
                style="font-size: 12px"
                disabled
                :title="$t('Available soon')"
            >
                <PhWebhooksLogo :size="13" />
                {{ $t('Send to the webhook') }}
            </button>
        </div>
        <div
            style="
                font-size: 11px;
                color: var(--nc-neutral-600);
                margin-top: var(--nc-space-3);
            "
        >
            {{
                $t(
                    'A test delivery does not open an alert and stays out of the log.',
                )
            }}
        </div>
    </SectionCard>
</template>
