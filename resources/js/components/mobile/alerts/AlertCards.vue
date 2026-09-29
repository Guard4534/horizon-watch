<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AlertActions from '@/components/monitoring/alerts/AlertActions.vue';
import AlertDetail from '@/components/monitoring/alerts/AlertDetail.vue';
import AlertMarks from '@/components/monitoring/alerts/AlertMarks.vue';
import AlertOpened from '@/components/monitoring/alerts/AlertOpened.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import { useEnvironmentHref } from '@/composables/useEnvironmentHref';
import { ruleLabel } from '@/lib/alertRules';
import { severityColor, severityIcon } from '@/lib/alerts';
import { formatElapsed } from '@/lib/monitoring';

defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
    empty: string;
}>();

const environmentHref = useEnvironmentHref();
</script>

<template>
    <div class="flex flex-col" style="gap: var(--nc-space-2)">
        <div v-if="alerts.length === 0" class="nc-card nc-t-xs nc-tone-muted">
            {{ empty }}
        </div>
        <div
            v-for="alert in alerts"
            :key="alert.id"
            class="nc-card alert-card"
            :class="{
                'is-critical':
                    alert.severity === 'critical' && alert.state === 'open',
            }"
        >
            <div class="flex items-start gap-[9px]">
                <component
                    :is="severityIcon(alert.severity)"
                    :size="15"
                    class="mt-px flex-none"
                    :style="{ color: severityColor(alert.severity) }"
                />
                <div class="min-w-0 flex-1">
                    <Link
                        v-if="alert.environmentId !== null"
                        :href="environmentHref(alert.environmentId)"
                        class="title block"
                        >{{ ruleLabel(alert.metric) }}</Link
                    >
                    <span v-else class="title block">{{
                        ruleLabel(alert.metric)
                    }}</span>
                    <div class="nc-t-2xs nc-tone-soft mt-[3px]">
                        <AlertDetail :alert="alert" />
                    </div>
                    <div
                        class="nc-tone-muted mt-[4px] flex items-center gap-[6px]"
                        style="font-size: 10px"
                    >
                        <EnvSwatch
                            :color="alert.color"
                            shape="bar"
                            :size="10"
                        />
                        <span class="min-w-0 truncate"
                            >{{ alert.applicationName }} /
                            {{ alert.environmentName }}</span
                        >
                        <span class="nc-num ml-auto flex-none"
                            ><template
                                v-if="alert.resolvedMinutesAgo !== null"
                                >{{
                                    $t('resolved :elapsed', {
                                        elapsed: formatElapsed(
                                            alert.resolvedMinutesAgo,
                                        ),
                                    })
                                }}</template
                            ><AlertOpened v-else :alert="alert"
                        /></span>
                    </div>
                    <AlertMarks :alert="alert" class="mt-[6px]" />
                    <AlertActions :alert="alert" block class="mt-[8px]" />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.alert-card {
    padding: var(--nc-space-3);
}

.alert-card.is-critical {
    background: color-mix(in srgb, var(--st-down) 12%, var(--nc-surface));
}

.title {
    font-size: 12px;
    line-height: 1.35;
    color: inherit;
    text-decoration: none;
}
</style>
