<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhWarning, PhWarningOctagon } from '@phosphor-icons/vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatRule } from '@/lib/alertRules';
import { formatMinutesAgo, formatWait } from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();
</script>

<template>
    <div class="overflow-x-auto">
        <table class="nc-table">
            <thead>
                <tr>
                    <th>{{ $t('Severity') }}</th>
                    <th>{{ $t('Rule') }}</th>
                    <th>{{ $t('Environment') }}</th>
                    <th>{{ $t('Detail') }}</th>
                    <th>{{ $t('Opened') }}</th>
                    <th>{{ $t('Channels') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="alert in alerts" :key="alert.id">
                    <td>
                        <span
                            class="inline-flex items-center gap-[6px]"
                            style="font-size: 12px"
                            :style="{ color: alert.severity === 'critical' ? 'var(--st-down)' : 'var(--st-warn)' }"
                        >
                            <component :is="alert.severity === 'critical' ? PhWarningOctagon : PhWarning" :size="14" />
                            {{ alert.severity === 'critical' ? $t('Critical') : 'Warning' }}
                        </span>
                    </td>
                    <td style="font-size: 12px; letter-spacing: 0.01em">{{ formatRule(alert.metric, alert.threshold, alert.unit) }}</td>
                    <td>
                        <Link :href="showEnvironment({ current_team: slug, environment: alert.environmentId })" class="inline-flex items-center gap-[7px]" style="font-size: 12px; color: inherit">
                            <EnvSwatch :color="alert.color" shape="bar" :size="14" />
                            {{ alert.applicationName }} / {{ alert.environmentName }}
                        </Link>
                    </td>
                    <td class="max-w-[250px]" style="font-size: 12px; color: var(--nc-neutral-400)">
                        {{ alert.severity === 'critical'
                            ? $tChoice('0 active workers across :count node|0 active workers across :count nodes', alert.nodeCount)
                            : $t('max wait :wait on 3 queues', { wait: formatWait(alert.maxWaitSeconds) }) }}
                    </td>
                    <td class="whitespace-nowrap" style="font-size: 12px; color: var(--nc-neutral-600)">{{ formatMinutesAgo(alert.minutesAgo) }}</td>
                    <td class="whitespace-nowrap" style="font-size: 12px; color: var(--nc-neutral-500)">{{ alert.channels.map((channel) => (channel === 'mail' ? 'email' : 'webhook')).join(' · ') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
