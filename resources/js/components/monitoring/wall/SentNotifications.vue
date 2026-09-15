<script setup lang="ts">
import { PhEnvelopeSimple, PhWebhooksLogo } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import SectionCard from '@/components/nocturne/SectionCard.vue';

defineProps<{
    notifications: App.Data.Monitoring.SentNotificationData[];
}>();

function text(notification: App.Data.Monitoring.SentNotificationData): string {
    switch (notification.kind) {
        case 'critical_alert':
            return `${trans('CRITICAL')} · ${notification.subject}`;
        case 'warning_digest':
            return trans('Warning digest · :count environments', { count: notification.subject });
        case 'resolved':
            return `${trans('Resolved')} · ${notification.subject}`;
        default:
            return `POST ${notification.subject}`;
    }
}
</script>

<template>
    <SectionCard :title="$t('Notifications sent')">
        <div class="flex flex-col" style="gap: var(--nc-space-2); font-size: 12px">
            <div v-for="(notification, index) in notifications" :key="index" class="flex items-center gap-2">
                <component
                    :is="notification.channel === 'webhook' ? PhWebhooksLogo : PhEnvelopeSimple"
                    :size="14"
                    class="flex-none"
                    style="color: var(--nc-neutral-500)"
                />
                <span class="min-w-0 truncate">{{ text(notification) }}</span>
                <span class="ml-auto flex-none" style="font-size: 11px; color: var(--nc-neutral-600)">{{ notification.minutesAgo }} min</span>
            </div>
        </div>
    </SectionCard>
</template>
