import { trans } from 'laravel-vue-i18n';
import { statusColor, statusLabel } from '@/lib/monitoring';

export function lampColor(
    status: App.Enums.EnvironmentStatus | null,
    tone?: string,
): string {
    if (tone) {
        return tone;
    }

    return status === null ? 'var(--nc-neutral-500)' : statusColor(status);
}

export function lampLabel(
    status: App.Enums.EnvironmentStatus | null,
    label?: string,
): string {
    if (label) {
        return label;
    }

    return status === null ? trans('No reading yet') : statusLabel(status);
}
