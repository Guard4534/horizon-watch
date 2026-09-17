<script setup lang="ts">
import { computed } from 'vue';
import {
    failedColor,
    hasMeasurement,
    pendingTone,
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { failedLabel, failedWindowShort } from '@/lib/failedWindow';
import { formatCount, formatWait, waitColor } from '@/lib/monitoring';

const { environments, thresholds } = defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
    thresholds: Record<string, number>;
}>();

const threshold = (metric: App.Enums.AlertRuleMetric) =>
    thresholds[metric] ?? 0;

const windows = computed(
    () =>
        new Set(
            environments
                .filter(hasMeasurement)
                .map((environment) => environment.failedWindowMinutes),
        ),
);

const header = computed(() => {
    if (windows.value.size === 0) {
        return 'Failed';
    }

    return windows.value.size === 1
        ? failedLabel([...windows.value][0])
        : failedLabel(null);
});
</script>

<template>
    <SectionCard :title="$t('Environment comparison')">
        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Environment') }}</th>
                        <th style="text-align: right">Pending</th>
                        <th style="text-align: right">Max wait</th>
                        <th style="text-align: right">
                            {{ header }}
                        </th>
                        <th style="text-align: right">Workers</th>
                        <th style="text-align: right">jobs/min</th>
                    </tr>
                </thead>
                <tbody class="nc-num">
                    <tr
                        v-for="environment in environments"
                        :key="environment.id"
                    >
                        <td>
                            <span class="inline-flex items-center gap-2"
                                ><EnvSwatch :color="environment.color" />{{
                                    environment.name
                                }}</span
                            >
                        </td>
                        <template v-if="!hasMeasurement(environment)">
                            <td
                                v-for="column in 5"
                                :key="column"
                                style="
                                    text-align: right;
                                    color: var(--nc-neutral-600);
                                "
                            >
                                <span
                                    v-if="column === 1"
                                    style="margin-right: 6px; font-size: 11px"
                                    :style="{ color: statusTone(environment) }"
                                    >{{ statusText(environment) }}</span
                                >—
                            </td>
                        </template>
                        <template v-else>
                            <td
                                style="text-align: right"
                                :style="{
                                    color: pendingTone(
                                        environment.pending,
                                        threshold('queue.pending'),
                                    ),
                                }"
                            >
                                {{ formatCount(environment.pending) }}
                            </td>
                            <td
                                style="text-align: right"
                                :style="{
                                    color: waitColor(
                                        environment.maxWaitSeconds,
                                        threshold('queue.max_wait'),
                                    ),
                                }"
                            >
                                {{ formatWait(environment.maxWaitSeconds) }}
                            </td>
                            <td
                                style="text-align: right"
                                :style="{
                                    color: failedColor(
                                        environment.failedLastHour,
                                        threshold('jobs.failed_per_hour'),
                                    ),
                                }"
                            >
                                {{ formatCount(environment.failedInWindow)
                                }}<span
                                    v-if="windows.size > 1"
                                    style="
                                        margin-left: 4px;
                                        font-size: 10px;
                                        color: var(--nc-neutral-500);
                                    "
                                    >{{
                                        failedWindowShort(
                                            environment.failedWindowMinutes,
                                        )
                                    }}</span
                                >
                            </td>
                            <td style="text-align: right">
                                {{ environment.workers }}
                            </td>
                            <td style="text-align: right">
                                {{ environment.jobsPerMinute }}
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
