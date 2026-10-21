import { trans } from 'laravel-vue-i18n';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import {
    formatAge,
    needsAttention,
} from '@/components/monitoring/environment/readings';
import { thresholdOf } from '@/lib/alertRules';

export function useEnvironmentDetail(
    source: () => App.Data.Pages.EnvironmentDetailPageData,
): {
    environment: ComputedRef<App.Data.Monitoring.EnvironmentData>;
    threshold: (metric: App.Enums.AlertRuleMetric) => number | null;
    incident: ComputedRef<Exclude<
        App.Enums.EnvironmentStatus,
        'active'
    > | null>;
    incidentAlert: ComputedRef<App.Data.Monitoring.AlertData | null>;
    lastKnown: ComputedRef<string | null>;
} {
    const page = computed(source);
    const environment = computed(() => page.value.environment);

    const threshold = (metric: App.Enums.AlertRuleMetric) =>
        thresholdOf(environment.value, metric);

    const incident = computed(() => {
        const status = environment.value.status;

        return needsAttention(environment.value) &&
            status !== null &&
            status !== 'active'
            ? status
            : null;
    });

    const incidentAlert = computed(
        () => page.value.alerts.find((alert) => alert.state === 'open') ?? null,
    );

    const lastKnown = computed(() => {
        if (
            environment.value.readingError === null &&
            !environment.value.stale
        ) {
            return null;
        }

        const ages = page.value.nodes.map((node) => node.seenSecondsAgo);

        return ages.length
            ? trans('last known · :time ago', {
                  time: formatAge(Math.min(...ages)),
              })
            : trans('last known');
    });

    return { environment, threshold, incident, incidentAlert, lastKnown };
}
