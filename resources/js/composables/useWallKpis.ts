import { trans } from 'laravel-vue-i18n';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import { severityColor } from '@/lib/alerts';
import { failedWindowNote } from '@/lib/failedWindow';
import { formatCount } from '@/lib/monitoring';

export type WallKpiKey = 'healthy' | 'openAlerts' | 'pending' | 'failed';

export type WallKpi = {
    key: WallKpiKey;
    label: string;
    value: string;
    color?: string;
    note?: string;
};

export function useWallKpis(
    source: () => App.Data.Pages.WallKpisData,
): ComputedRef<WallKpi[]> {
    return computed(() => {
        const kpis = source();
        const failed = formatCount(kpis.failedLastHour);

        return [
            {
                key: 'healthy',
                label: trans('Healthy'),
                value: `${kpis.environmentsHealthy} / ${kpis.environmentsJudged}`,
                note: trans('across all applications'),
            },
            {
                key: 'openAlerts',
                label: trans('Open alerts'),
                value: String(kpis.openAlerts),
                color: kpis.openAlertSeverity
                    ? severityColor(kpis.openAlertSeverity)
                    : undefined,
            },
            {
                key: 'pending',
                label: trans('Pending'),
                value: formatCount(kpis.pendingTotal),
                note: trans('sum of every queue'),
            },
            {
                key: 'failed',
                label: trans('Failed · last hour'),
                value: kpis.failedLastHourCapped
                    ? trans('at least :count', { count: failed })
                    : failed,
                color: kpis.failedSeverity
                    ? severityColor(kpis.failedSeverity)
                    : undefined,
                note: trans('Horizon: :count, :window', {
                    count: formatCount(kpis.failedInWindow),
                    window: failedWindowNote(kpis.failedWindowMinutes),
                }),
            },
        ];
    });
}
