<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import AlertActions from '@/components/monitoring/alerts/AlertActions.vue';
import AlertDetail from '@/components/monitoring/alerts/AlertDetail.vue';
import AlertMarks from '@/components/monitoring/alerts/AlertMarks.vue';
import AlertOpened from '@/components/monitoring/alerts/AlertOpened.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatRule } from '@/lib/alertRules';
import {
    channelIcon,
    channelLabel,
    severityColor,
    severityIcon,
    severityLabel,
} from '@/lib/alerts';
import { formatElapsed } from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
    empty: string;
}>();

const slug = useTeamSlug();

function environmentHref(environmentId: string): string {
    return showEnvironment({
        current_team: slug.value,
        environment: environmentId,
    }).url;
}

function openRow(
    alert: App.Data.Monitoring.AlertData,
    event: MouseEvent,
): void {
    if (
        alert.environmentId === null ||
        (event.target as HTMLElement).closest('a, button, [role="menu"]')
    ) {
        return;
    }

    router.visit(environmentHref(alert.environmentId));
}
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
                    <td
                        colspan="7"
                        style="font-size: 12px; color: var(--nc-neutral-500)"
                    >
                        {{ empty }}
                    </td>
                </tr>
                <tr
                    v-for="alert in alerts"
                    :key="alert.id"
                    :class="{ 'row-link': alert.environmentId !== null }"
                    @click="openRow(alert, $event)"
                >
                    <td>
                        <span
                            class="inline-flex items-center gap-[6px] whitespace-nowrap"
                            style="font-size: 12px"
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
                        class="whitespace-nowrap"
                        style="font-size: 12px; letter-spacing: 0.01em"
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
                            class="env-link inline-flex items-center gap-[7px]"
                            style="font-size: 12px"
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
                            class="inline-flex items-center gap-[7px]"
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
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
                    <td
                        class="max-w-[260px]"
                        style="font-size: 12px; color: var(--nc-neutral-400)"
                    >
                        <AlertDetail :alert="alert" />
                        <AlertMarks :alert="alert" class="mt-[4px]" />
                    </td>
                    <td
                        class="nc-num whitespace-nowrap"
                        style="font-size: 12px; color: var(--nc-neutral-600)"
                    >
                        <AlertOpened :alert="alert" />
                        <div
                            v-if="alert.resolvedMinutesAgo !== null"
                            style="font-size: 11px; color: var(--st-ok)"
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
                    <td
                        class="whitespace-nowrap"
                        style="font-size: 12px; color: var(--nc-neutral-500)"
                    >
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
                    <td class="whitespace-nowrap" style="text-align: right">
                        <AlertActions :alert="alert" layout="icons" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.row-link {
    cursor: pointer;
}

.env-link {
    color: inherit;
    text-decoration: none;
}

.env-link:hover {
    color: var(--nc-accent);
}
</style>
