<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { channelIcon } from '@/lib/alerts';
import { formatElapsed } from '@/lib/monitoring';

defineProps<{
    notifications: App.Data.Monitoring.SentNotificationData[];
}>();

function text(
    notification: App.Data.Monitoring.SentNotificationData,
): string | null {
    switch (notification.kind) {
        case 'critical_alert':
            return `${trans('CRITICAL')} · ${notification.subject}`;
        case 'warning_digest':
            return null;
        case 'resolved':
            return `${trans('Resolved')} · ${notification.subject}`;
        case 'webhook_delivery':
            return `POST · ${notification.subject}`;
        case 'test':
            return notification.channel === 'webhook'
                ? trans('Test delivery to the webhook')
                : trans('Test email');
    }
}
</script>

<template>
    <SectionCard :title="$t('Notifications sent')">
        <div
            v-if="notifications.length === 0"
            style="font-size: 12px; color: var(--nc-neutral-500)"
        >
            {{ $t('No notification sent yet.') }}
        </div>
        <div
            v-else
            class="flex flex-col"
            style="gap: var(--nc-space-2); font-size: 12px"
        >
            <div
                v-for="(notification, index) in notifications"
                :key="index"
                class="flex items-start gap-2"
            >
                <component
                    :is="channelIcon(notification.channel)"
                    :size="14"
                    class="mt-[2px] flex-none"
                    :style="{
                        color:
                            notification.status === 'failed'
                                ? 'var(--st-down)'
                                : 'var(--nc-neutral-500)',
                    }"
                />
                <div class="min-w-0 flex-1">
                    <div class="truncate">
                        {{
                            text(notification) ??
                            $tChoice(
                                'Warning digest · :count environment|Warning digest · :count environments',
                                Number(notification.subject),
                            )
                        }}
                    </div>
                    <div
                        v-if="
                            notification.target ||
                            notification.status === 'failed'
                        "
                        class="flex items-center gap-[6px]"
                        style="font-size: 11px; color: var(--nc-neutral-600)"
                    >
                        <span
                            v-if="notification.status === 'failed'"
                            class="flex-none"
                            style="color: var(--st-down)"
                            >{{ $t('not delivered') }}</span
                        >
                        <span
                            v-if="notification.target"
                            class="min-w-0 truncate"
                            >{{ notification.target }}</span
                        >
                    </div>
                </div>
                <span
                    class="nc-num flex-none"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                    >{{ formatElapsed(notification.minutesAgo) }}</span
                >
            </div>
        </div>
    </SectionCard>
</template>
