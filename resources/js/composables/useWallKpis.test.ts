import { describe, expect, it } from 'vite-plus/test';
import { useWallKpis } from '@/composables/useWallKpis';

const kpis: App.Data.Pages.WallKpisData = {
    environmentsHealthy: 3,
    environmentsJudged: 5,
    environmentsNeedingAttention: 2,
    openAnomalies: 2,
    openAlertSeverity: 'critical',
    pendingTotal: 1250,
    failedLastHour: 42,
    failedLastHourCapped: false,
    failedSeverity: 'warning',
    failedInWindow: 300,
    failedWindowMinutes: 10080,
};

describe('useWallKpis', () => {
    it('defines the four wall figures once, in order', () => {
        const items = useWallKpis(() => kpis).value;

        expect(
            items.map((kpi) => [kpi.key, kpi.label, kpi.value, kpi.color]),
        ).toEqual([
            ['healthy', 'Healthy', '3 / 5', undefined],
            ['openAlerts', 'Open alerts', '2', 'var(--st-down)'],
            ['pending', 'Pending', '1.3k', undefined],
            ['failed', 'Failed · last hour', '42', 'var(--st-warn)'],
        ]);
        expect(items[3].note).toBe('Horizon: 300, last 7 days');
    });

    it('says at least for a capped last hour and stays neutral without a breach', () => {
        const [, openAlerts, , failed] = useWallKpis(() => ({
            ...kpis,
            openAnomalies: 0,
            openAlertSeverity: null,
            failedLastHourCapped: true,
            failedSeverity: null,
        })).value;

        expect(openAlerts.value).toBe('0');
        expect(openAlerts.color).toBeUndefined();
        expect(failed.value).toBe('at least 42');
        expect(failed.color).toBeUndefined();
    });
});
