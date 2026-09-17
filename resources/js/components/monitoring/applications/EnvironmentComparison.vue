<script setup lang="ts">
import { computed } from 'vue';
import {
    failedColor,
    statusText,
} from '@/components/monitoring/environment/readings';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { failedLabel } from '@/lib/failedWindow';
import { formatCount, formatWait, waitColor } from '@/lib/monitoring';

const { environments, failedPerHourThreshold } = defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
    failedPerHourThreshold: number;
}>();

// Rows with a reading only: the others carry zeros that mean nothing.
const windows = computed(
    () =>
        new Set(
            environments
                .filter((environment) => environment.status !== null)
                .map((environment) => environment.failedWindowMinutes),
        ),
);

// One window for the whole column when the rows agree; otherwise the
// header says so and each cell names its own.
const commonWindow = computed(() =>
    windows.value.size <= 1
        ? ([...windows.value][0] ??
          environments[0]?.failedWindowMinutes ??
          1440)
        : null,
);

// Units read the same in both languages.
const windowShort = (minutes: number): string => {
    if (minutes % 1440 === 0) {
        return `${minutes / 1440}d`;
    }

    return minutes % 60 === 0 ? `${minutes / 60}h` : `${minutes} min`;
};
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
                            {{ failedLabel(commonWindow) }}
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
                        <td
                            v-if="environment.status === null"
                            colspan="5"
                            style="
                                text-align: right;
                                font-size: 12px;
                                color: var(--nc-neutral-500);
                            "
                        >
                            {{ statusText(environment) }}
                        </td>
                        <template v-else>
                            <td style="text-align: right">
                                {{ formatCount(environment.pending) }}
                            </td>
                            <td
                                style="text-align: right"
                                :style="{
                                    color: waitColor(
                                        environment.maxWaitSeconds,
                                    ),
                                }"
                            >
                                {{ formatWait(environment.maxWaitSeconds) }}
                            </td>
                            <td
                                style="text-align: right"
                                :style="{
                                    color: failedColor(
                                        environment.failedLast24Hours,
                                        environment.failedWindowMinutes,
                                        failedPerHourThreshold,
                                    ),
                                }"
                            >
                                {{ formatCount(environment.failedLast24Hours)
                                }}<span
                                    v-if="commonWindow === null"
                                    style="
                                        margin-left: 4px;
                                        font-size: 10px;
                                        color: var(--nc-neutral-500);
                                    "
                                    >{{
                                        windowShort(
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
