<script setup lang="ts">
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { formatCount, formatWait, waitColor } from '@/lib/monitoring';

defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
}>();
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
                        <th style="text-align: right">Failed 24h</th>
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
                        <td style="text-align: right">
                            {{ formatCount(environment.pending) }}
                        </td>
                        <td
                            style="text-align: right"
                            :style="{
                                color: waitColor(environment.maxWaitSeconds),
                            }"
                        >
                            {{ formatWait(environment.maxWaitSeconds) }}
                        </td>
                        <td style="text-align: right">
                            {{ environment.failedLast24Hours }}
                        </td>
                        <td style="text-align: right">
                            {{ environment.workers }}
                        </td>
                        <td style="text-align: right">
                            {{ environment.jobsPerMinute }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
