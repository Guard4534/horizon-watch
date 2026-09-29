<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AlertActions from '@/components/monitoring/alerts/AlertActions.vue';
import AlertDetail from '@/components/monitoring/alerts/AlertDetail.vue';
import AlertMarks from '@/components/monitoring/alerts/AlertMarks.vue';
import AlertOpened from '@/components/monitoring/alerts/AlertOpened.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import { useEnvironmentHref } from '@/composables/useEnvironmentHref';
import { formatRule } from '@/lib/alertRules';
import {
    channelIcon,
    channelLabel,
    severityColor,
    severityIcon,
    severityLabel,
} from '@/lib/alerts';
import { formatElapsed } from '@/lib/monitoring';

defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
    empty: string;
}>();

const environmentHref = useEnvironmentHref();
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
                    <th />
                </tr>
            </thead>
            <tbody>
                <tr v-if="alerts.length === 0">
                    <td colspan="7" class="nc-t-xs nc-tone-muted">
                        {{ empty }}
                    </td>
                </tr>
                <tr v-for="alert in alerts" :key="alert.id">
                    <td>
                        <span
                            class="nc-t-xs inline-flex items-center gap-[6px] whitespace-nowrap"
                            :style="{ color: severityColor(alert.severity) }"
                        >
                            <component
                                :is="severityIcon(alert.severity)"
                                :size="14"
                            />
                            {{ severityLabel(alert.severity) }}
                        </span>
                    </td>
                    <td
                        class="nc-t-xs whitespace-nowrap"
                        style="letter-spacing: 0.01em"
                    >
                        {{
                            formatRule(
                                alert.metric,
                                alert.threshold,
                                alert.unit,
                            )
                        }}
                    </td>
                    <td>
                        <Link
                            v-if="alert.environmentId !== null"
                            :href="environmentHref(alert.environmentId)"
                            class="nc-t-xs env-link inline-flex items-center gap-[7px]"
                        >
                            <EnvSwatch
                                :color="alert.color"
                                shape="bar"
                                :size="14"
                            />
                            {{ alert.applicationName }} /
                            {{ alert.environmentName }}
                        </Link>
                        <span
                            v-else
                            class="nc-t-xs nc-tone-soft inline-flex items-center gap-[7px]"
                            :title="$t('This environment has been deleted')"
                        >
                            <EnvSwatch
                                :color="alert.color"
                                shape="bar"
                                :size="14"
                            />
                            {{ alert.applicationName }} /
                            {{ alert.environmentName }}
                        </span>
                    </td>
                    <td class="nc-t-xs nc-tone-soft max-w-[260px]">
                        <AlertDetail :alert="alert" />
                        <AlertMarks :alert="alert" class="mt-[4px]" />
                    </td>
                    <td class="nc-num nc-t-xs nc-tone-faint whitespace-nowrap">
                        <AlertOpened :alert="alert" />
                        <div
                            v-if="alert.resolvedMinutesAgo !== null"
                            class="nc-t-2xs"
                            style="color: var(--st-ok)"
                        >
                            {{
                                $t('resolved :elapsed', {
                                    elapsed: formatElapsed(
                                        alert.resolvedMinutesAgo,
                                    ),
                                })
                            }}
                        </div>
                    </td>
                    <td class="nc-t-xs nc-tone-muted whitespace-nowrap">
                        <span
                            v-if="alert.channels.length"
                            class="inline-flex items-center gap-[8px]"
                        >
                            <span
                                v-for="channel in alert.channels"
                                :key="channel"
                                class="inline-flex"
                                role="img"
                                :title="channelLabel(channel)"
                                :aria-label="channelLabel(channel)"
                            >
                                <component
                                    :is="channelIcon(channel)"
                                    :size="14"
                                />
                            </span>
                        </span>
                        <span v-else>—</span>
                    </td>
                    <td class="nc-right whitespace-nowrap">
                        <AlertActions :alert="alert" layout="icons" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.env-link {
    color: inherit;
    text-decoration: none;
}

.env-link:hover {
    color: var(--nc-accent);
}
</style>
