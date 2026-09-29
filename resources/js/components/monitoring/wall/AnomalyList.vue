<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCheckCircle } from '@phosphor-icons/vue';
import AlertActions from '@/components/monitoring/alerts/AlertActions.vue';
import AlertMarks from '@/components/monitoring/alerts/AlertMarks.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatRule, ruleIcon, ruleLabel } from '@/lib/alertRules';
import { channelIcon, channelLabel } from '@/lib/alerts';
import { formatElapsed } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    anomalies: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();

function color(alert: App.Data.Monitoring.AlertData): string {
    if (alert.metric === 'horizon.paused') {
        return 'var(--st-off)';
    }

    return alert.severity === 'critical' ? 'var(--st-down)' : 'var(--st-warn)';
}
</script>

<template>
    <SectionCard :title="$t('Open anomalies')">
        <template #actions>
            <Link :href="alertsIndex(slug)" class="nc-t-2xs">{{
                $t('Full log')
            }}</Link>
        </template>
        <div
            v-if="anomalies.length === 0"
            class="nc-t-xs nc-tone-muted flex items-center gap-[9px]"
        >
            <PhCheckCircle
                :size="14"
                class="flex-none"
                style="color: var(--st-ok)"
            />
            {{
                $t(
                    'No open anomalies: every watched environment is within its thresholds.',
                )
            }}
        </div>
        <div v-else class="flex flex-col" style="gap: var(--nc-space-3)">
            <div v-for="alert in anomalies" :key="alert.id" class="anomaly">
                <div class="flex items-start gap-[9px]">
                    <component
                        :is="ruleIcon(alert.metric)"
                        :size="15"
                        class="mt-[2px] flex-none"
                        :style="{ color: color(alert) }"
                    />
                    <div class="min-w-0 flex-1">
                        <Link
                            v-if="alert.environmentId !== null"
                            :href="
                                showEnvironment({
                                    current_team: slug,
                                    environment: alert.environmentId,
                                })
                            "
                            class="title block"
                            >{{ ruleLabel(alert.metric) }}</Link
                        >
                        <span v-else class="title block">{{
                            ruleLabel(alert.metric)
                        }}</span>
                        <div
                            class="nc-t-2xs nc-tone-muted mt-[3px] flex items-center gap-[6px]"
                        >
                            <EnvSwatch
                                :color="alert.color"
                                shape="bar"
                                :size="11"
                            />
                            <span class="min-w-0 truncate"
                                >{{ alert.applicationName }} /
                                {{ alert.environmentName }} ·
                                {{ formatElapsed(alert.minutesAgo) }}</span
                            >
                        </div>
                        <div class="nc-code nc-t-2xs nc-tone-faint mt-[3px]">
                            {{
                                formatRule(
                                    alert.metric,
                                    alert.threshold,
                                    alert.unit,
                                )
                            }}
                        </div>
                        <div
                            class="nc-t-2xs nc-tone-faint mt-[3px] flex items-center gap-[6px]"
                        >
                            <template v-if="alert.channels.length">
                                <span
                                    v-for="channel in alert.channels"
                                    :key="channel"
                                    class="inline-flex items-center gap-[4px]"
                                >
                                    <component
                                        :is="channelIcon(channel)"
                                        :size="12"
                                    />{{ channelLabel(channel) }}
                                </span>
                            </template>
                            <span v-else>{{ $t('not sent yet') }}</span>
                        </div>
                        <AlertMarks :alert="alert" class="mt-[4px]" />
                        <AlertActions
                            :alert="alert"
                            class="mt-[var(--nc-space-2)]"
                        />
                    </div>
                </div>
            </div>
        </div>
    </SectionCard>
</template>

<style scoped>
.anomaly {
    padding: 0 0 var(--nc-space-3);
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.anomaly:last-child {
    padding-bottom: 0;
    border-bottom: 0;
}

.title {
    font-size: 13px;
    line-height: 1.35;
    color: inherit;
    text-decoration: none;
}

.title:hover {
    color: var(--nc-accent);
}
</style>
