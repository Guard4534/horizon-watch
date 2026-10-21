<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { channelIcon } from '@/lib/alerts';
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

function errorLabel(
    error: App.Enums.DeliveryError,
    httpStatus: number | null,
): string {
    if (httpStatus !== null) {
        return `HTTP ${httpStatus}`;
    }

    switch (error) {
        case 'timeout':
            return trans('timeout');
        case 'blocked':
            return trans('blocked');
        case 'http_3xx':
            return trans('http_3xx');
        case 'http_4xx':
            return trans('http_4xx');
        case 'http_5xx':
            return trans('http_5xx');
        case 'unreachable':
            return trans('unreachable');
        case 'mail':
            return trans('mail');
        case 'mail_unreachable':
            return trans('mail server unreachable');
        case 'mail_authentication':
            return trans('mail server refused the login');
        case 'mail_rejected':
            return trans('refused by the mail server');
        case 'secret_unreadable':
            return trans('signing secret unreadable, regenerate it');
        case 'no_longer_due':
            return trans('alert resolved, handled or muted first');
        case 'opening_not_delivered':
            return trans('its opening never reached this destination');
    }
}

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
        ? `${status} · ${errorLabel(notification.error, notification.httpStatus)}`
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
