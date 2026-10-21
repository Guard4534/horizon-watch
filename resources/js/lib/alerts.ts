import {
    PhCheckCircle,
    PhEnvelopeSimple,
    PhWebhooksLogo,
} from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { ruleIcon } from '@/lib/alertRules';
import type { Component } from 'vue';
import { formatInstant } from '@/lib/datetime';

export const MUTE_DURATIONS: App.Enums.MuteDuration[] = [
    '1h',
    '4h',
    '24h',
    'resolved',
];

export function muteDurationLabel(duration: App.Enums.MuteDuration): string {
    switch (duration) {
        case '1h':
            return trans('For 1 hour');
        case '4h':
            return trans('For 4 hours');
        case '24h':
            return trans('For 24 hours');
        case 'resolved':
            return trans('Until resolved');
    }
}

export function severityColor(severity: App.Enums.AlertSeverity): string {
    return severity === 'critical' ? 'var(--st-down)' : 'var(--st-warn)';
}

export function severityLabel(severity: App.Enums.AlertSeverity): string {
    return severity === 'critical' ? trans('Critical') : trans('Warning');
}

export function channelIcon(channel: App.Enums.NotificationChannel): Component {
    return channel === 'webhook' ? PhWebhooksLogo : PhEnvelopeSimple;
}

export function channelLabel(channel: App.Enums.NotificationChannel): string {
    return channel === 'webhook' ? trans('Webhook') : trans('Email');
}

export function deliveryErrorLabel(
    error: App.Enums.DeliveryError,
    httpStatus: number | null,
): string {
    if (httpStatus !== null) {
        return `HTTP ${httpStatus}`;
    }

    switch (error) {
        case 'timeout':
            return trans('the destination did not answer in time');
        case 'blocked':
            return trans(
                'the address points to a network the panel does not call',
            );
        case 'http_3xx':
            return trans('redirect not followed');
        case 'http_4xx':
            return trans('refused by the destination');
        case 'http_5xx':
            return trans('error at the destination');
        case 'unreachable':
            return trans('destination unreachable');
        case 'mail':
            return trans('the email could not be sent');
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

export function isMuted(alert: App.Data.Monitoring.AlertData): boolean {
    return alert.mutedUntilResolved || alert.mutedUntil !== null;
}

export function alertTone(alert: App.Data.Monitoring.AlertData): string {
    switch (alert.state) {
        case 'resolved':
            return 'var(--st-ok)';
        case 'muted':
            return 'var(--nc-neutral-500)';
        case 'open':
            return severityColor(alert.severity);
    }
}

export function alertIcon(alert: App.Data.Monitoring.AlertData): Component {
    return alert.state === 'resolved' ? PhCheckCircle : ruleIcon(alert.metric);
}

export function mutedText(
    alert: App.Data.Monitoring.AlertData,
    locale: string,
): string | null {
    const name = alert.mutedBy?.name;

    if (alert.mutedUntilResolved) {
        return name
            ? trans('Muted by :name until resolved', { name })
            : trans('Muted until resolved');
    }

    if (alert.mutedUntil === null) {
        return null;
    }

    const time = formatInstant(alert.mutedUntil, locale);

    if (alert.mutedForMaintenance) {
        return trans('Muted for maintenance until :time', { time });
    }

    return name
        ? trans('Muted by :name until :time', { name, time })
        : trans('Muted until :time', { time });
}

export function pageCount(total: number, perPage: number): number {
    return Math.max(1, Math.ceil(total / Math.max(1, perPage)));
}
