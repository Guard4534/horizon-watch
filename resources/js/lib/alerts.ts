import {
    PhEnvelopeSimple,
    PhWarning,
    PhWarningOctagon,
    PhWebhooksLogo,
} from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';

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

export function severityIcon(severity: App.Enums.AlertSeverity): Component {
    return severity === 'critical' ? PhWarningOctagon : PhWarning;
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

export function isMuted(alert: App.Data.Monitoring.AlertData): boolean {
    return alert.mutedUntilResolved || alert.mutedUntil !== null;
}

function formatMutedUntil(
    iso: string,
    locale: string,
    now: Date = new Date(),
): string {
    const until = new Date(iso);
    const sameDay = until.toDateString() === now.toDateString();

    return new Intl.DateTimeFormat(locale, {
        ...(sameDay ? {} : { weekday: 'short' }),
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(until);
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

    const time = formatMutedUntil(alert.mutedUntil, locale);

    return name
        ? trans('Muted by :name until :time', { name, time })
        : trans('Muted until :time', { time });
}

export function pageCount(total: number, perPage: number): number {
    return Math.max(1, Math.ceil(total / Math.max(1, perPage)));
}
