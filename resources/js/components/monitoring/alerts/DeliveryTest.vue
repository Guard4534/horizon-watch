<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhEnvelopeSimple, PhWebhooksLogo } from '@phosphor-icons/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { test as sendTest } from '@/routes/alert-settings';

const { settings } = defineProps<{
    settings: App.Data.Monitoring.NotificationSettingsData;
}>();

const slug = useTeamSlug();

const sending = ref<App.Enums.NotificationChannel | null>(null);

function send(channel: App.Enums.NotificationChannel): void {
    router.post(
        sendTest(slug.value).url,
        { channel },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (sending.value = channel),
            onFinish: () => (sending.value = null),
            onError: (errors) => {
                const message = Object.values(errors)[0];

                if (message) {
                    toast.error(message);
                }
            },
        },
    );
}
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
                    'Sends a sample alert to the saved targets, so you know email and the webhook work before you actually need them.',
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
                        : $t('Only you')
                }}</span>
            </div>
            <div class="flex items-center gap-2">
                <PhWebhooksLogo :size="14" class="flex-none" />
                <span
                    class="min-w-0 truncate"
                    style="color: var(--nc-text); letter-spacing: 0.01em"
                    >{{ settings.webhookUrl || $t('No webhook') }}</span
                >
            </div>
        </div>
        <div class="flex flex-wrap" style="gap: var(--nc-space-2)">
            <button
                type="button"
                class="nc-btn nc-btn-secondary"
                style="font-size: 12px"
                :disabled="sending !== null"
                @click="send('mail')"
            >
                <PhEnvelopeSimple :size="13" />
                {{ $t('Send a test email') }}
            </button>
            <button
                type="button"
                class="nc-btn nc-btn-secondary"
                style="font-size: 12px"
                :disabled="sending !== null || !settings.webhookUrl"
                :title="
                    settings.webhookUrl ? undefined : $t('No webhook is saved.')
                "
                @click="send('webhook')"
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
                line-height: 1.5;
            "
        >
            {{
                $t(
                    'The test email also goes to you. A test opens no alert and shows up among the notifications sent on the status wall.',
                )
            }}
        </div>
    </SectionCard>
</template>
