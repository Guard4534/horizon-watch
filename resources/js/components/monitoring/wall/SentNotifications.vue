<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { channelIcon, deliveryErrorLabel } from '@/lib/alerts';
import { formatAgo } from '@/lib/monitoring';

defineProps<{
    notifications: App.Data.Monitoring.SentNotificationData[];
}>();

const WEBHOOK_EVENTS: Record<App.Enums.SentNotificationKind, string> = {
    critical_alert: 'alert.opened',
    critical_repeated: 'alert.repeated',
    resolved: 'alert.resolved',
    warning_digest: 'alert.digest',
    test: 'test',
};

function outcome(
    notification: App.Data.Monitoring.SentNotificationData,
): string | null {
    if (notification.status === 'sent') {
        return null;
    }

    const status =
        notification.status === 'failed'
            ? trans('not delivered')
            : trans('not sent');

    return notification.error
        ? `${status} · ${deliveryErrorLabel(notification.error, notification.httpStatus)}`
        : status;
}

function prefix(
    notification: App.Data.Monitoring.SentNotificationData,
): string {
    return notification.channel === 'webhook'
        ? `POST ${WEBHOOK_EVENTS[notification.kind]} · `
        : '';
}

function text(
    notification: App.Data.Monitoring.SentNotificationData,
): string | null {
    const webhook = notification.channel === 'webhook';
    const subject = `${prefix(notification)}${notification.subject}`;

    switch (notification.kind) {
        case 'critical_alert':
            return webhook ? subject : `${trans('CRITICAL')} · ${subject}`;
        case 'critical_repeated':
            return webhook
                ? subject
                : `${trans('CRITICAL · repeated')} · ${subject}`;
        case 'resolved':
            return webhook ? subject : `${trans('Resolved')} · ${subject}`;
        case 'warning_digest':
            return null;
        case 'test':
            return webhook
                ? trans('Test delivery to the webhook')
                : trans('Test email');
    }
}
</script>

<template>
    <SectionCard :title="$t('Notifications sent')">
        <div v-if="notifications.length === 0" class="nc-t-xs nc-tone-muted">
            {{ $t('No notification sent yet.') }}
        </div>
        <div
            v-else
            class="nc-t-xs flex flex-col"
            style="gap: var(--nc-space-2)"
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
                            prefix(notification) +
                                $tChoice(
                                    'Warning digest · :count environment|Warning digest · :count environments',
                                    Number(notification.subject),
                                )
                        }}
                    </div>
                    <div
                        v-if="notification.target || outcome(notification)"
                        class="nc-t-2xs nc-tone-faint flex items-center gap-[6px]"
                    >
                        <span
                            v-if="outcome(notification)"
                            class="flex-none"
                            :class="{
                                'nc-tone-down':
                                    notification.status === 'failed',
                            }"
                            >{{ outcome(notification) }}</span
                        >
                        <span
                            v-if="notification.target"
                            class="min-w-0 truncate"
                            >{{ notification.target }}</span
                        >
                    </div>
                </div>
                <span class="nc-num nc-t-2xs nc-tone-faint flex-none">{{
                    formatAgo(notification.minutesAgo * 60)
                }}</span>
            </div>
        </div>
    </SectionCard>
</template>
