<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    PhBellSlash,
    PhCheck,
    PhWarning,
    PhWarningOctagon,
} from '@phosphor-icons/vue';
import AlertDetail from '@/components/monitoring/alerts/AlertDetail.vue';
import AlertOpened from '@/components/monitoring/alerts/AlertOpened.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatRule } from '@/lib/alertRules';
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

// The whole row opens the environment, for a pointer. The environment name
// stays a real link for keyboard and screen-reader users; clicks on a button
// in the row (the disabled phase-4 actions) never navigate.
function openRow(
    alert: App.Data.Monitoring.AlertData,
    event: MouseEvent,
): void {
    if ((event.target as HTMLElement).closest('a, button')) {
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
                    class="row-link"
                    @click="openRow(alert, $event)"
                >
                    <td>
                        <span
                            class="inline-flex items-center gap-[6px]"
                            style="font-size: 12px"
                            :style="{
                                color:
                                    alert.severity === 'critical'
                                        ? 'var(--st-down)'
                                        : 'var(--st-warn)',
                            }"
                        >
                            <component
                                :is="
                                    alert.severity === 'critical'
                                        ? PhWarningOctagon
                                        : PhWarning
                                "
                                :size="14"
                            />
                            {{
                                alert.severity === 'critical'
                                    ? $t('Critical')
                                    : $t('Warning')
                            }}
                        </span>
                    </td>
                    <td style="font-size: 12px; letter-spacing: 0.01em">
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
                    </td>
                    <td
                        class="max-w-[250px]"
                        style="font-size: 12px; color: var(--nc-neutral-400)"
                    >
                        <AlertDetail :alert="alert" />
                    </td>
                    <td
                        class="whitespace-nowrap"
                        style="font-size: 12px; color: var(--nc-neutral-600)"
                    >
                        <AlertOpened :alert="alert" />
                    </td>
                    <td
                        class="whitespace-nowrap"
                        style="font-size: 12px; color: var(--nc-neutral-500)"
                    >
                        <!-- Nothing is delivered before the next release:
                             naming channels would suggest otherwise. -->
                        {{ $t('not sent yet') }}
                    </td>
                    <td class="whitespace-nowrap" style="text-align: right">
                        <!-- Muting and marking as handled arrive with the
                             next release; the row itself opens the
                             environment. -->
                        <button
                            type="button"
                            class="nc-btn nc-btn-ghost row-action"
                            disabled
                            :title="$t('Available soon')"
                            :aria-label="$t('Mute')"
                        >
                            <PhBellSlash :size="14" />
                        </button>
                        <button
                            type="button"
                            class="nc-btn nc-btn-ghost row-action"
                            disabled
                            :title="$t('Available soon')"
                            :aria-label="$t('Handled')"
                        >
                            <PhCheck :size="14" />
                        </button>
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

.row-action {
    font-size: 11px;
    padding: 2px 7px;
}
</style>
