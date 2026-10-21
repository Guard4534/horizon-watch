<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCheckCircle } from '@phosphor-icons/vue';
import AlertActions from '@/components/monitoring/alerts/AlertActions.vue';
import AlertMarks from '@/components/monitoring/alerts/AlertMarks.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCondition, ruleLabel } from '@/lib/alertRules';
import { alertIcon, alertTone, channelIcon, channelLabel } from '@/lib/alerts';
import { formatAgo } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();
</script>

<template>
    <SectionCard :title="$t('Open alerts')">
        <template #actions>
            <Link :href="alertsIndex(slug)" class="nc-t-2xs">{{
                $t('Full log')
            }}</Link>
        </template>
        <div
            v-if="alerts.length === 0"
            class="nc-t-xs nc-tone-muted flex items-center gap-[9px]"
        >
            <PhCheckCircle
                :size="14"
                class="flex-none"
                style="color: var(--st-ok)"
            />
            {{
                $t(
                    'No open alerts: every environment you can see is within its thresholds.',
                )
            }}
        </div>
        <div v-else class="flex flex-col" style="gap: var(--nc-space-3)">
            <div v-for="alert in alerts" :key="alert.id" class="open-alert">
                <div class="flex items-start gap-[9px]">
                    <component
                        :is="alertIcon(alert)"
                        :size="15"
                        class="mt-[2px] flex-none"
                        :style="{ color: alertTone(alert) }"
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
                            <span class="min-w-0 truncate"
                                >{{ alert.applicationName }} /
                                <EnvPill
                                    :name="alert.environmentName"
                                    :type="alert.environmentType"
                                    :size="11"
                                />
                                ·
                                {{ formatAgo(alert.minutesAgo * 60) }}</span
                            >
                        </div>
                        <div
                            class="nc-t-2xs nc-tone-faint mt-[3px]"
                            :title="alert.metric"
                        >
                            {{
                                formatCondition(
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
.open-alert {
    padding: 0 0 var(--nc-space-3);
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.open-alert:last-child {
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
