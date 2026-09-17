<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhBellSlash, PhCheck } from '@phosphor-icons/vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatRule, ruleIcon, ruleLabel } from '@/lib/alertRules';
import { formatMinutesAgo } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    anomalies: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();

// Keyed by what opened the anomaly, not by the environment's status: a
// paused Horizon can also carry a pending-jobs breach, and the two rows
// must not read the same. Titles and icons are the alerts page's own
// (lib/alertRules.ts), so the two pages name an anomaly alike.
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
            <Link :href="alertsIndex(slug)" style="font-size: 11px">{{
                $t('Full log')
            }}</Link>
        </template>
        <div class="flex flex-col" style="gap: var(--nc-space-3)">
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
                            :href="
                                showEnvironment({
                                    current_team: slug,
                                    environment: alert.environmentId,
                                })
                            "
                            class="title block"
                            >{{ ruleLabel(alert.metric) }}</Link
                        >
                        <div
                            class="mt-[3px] flex items-center gap-[6px]"
                            style="
                                font-size: 11px;
                                color: var(--nc-neutral-500);
                            "
                        >
                            <EnvSwatch
                                :color="alert.color"
                                shape="bar"
                                :size="11"
                            />
                            <span class="min-w-0 truncate"
                                >{{ alert.applicationName }} /
                                {{ alert.environmentName }} ·
                                <template v-if="alert.sinceTruncated">{{
                                    $t('more than 24 h')
                                }}</template>
                                <template v-else>{{
                                    formatMinutesAgo(alert.minutesAgo)
                                }}</template></span
                            >
                        </div>
                        <div
                            class="nc-code mt-[3px]"
                            style="
                                font-size: 11px;
                                color: var(--nc-neutral-600);
                            "
                        >
                            {{
                                formatRule(
                                    alert.metric,
                                    alert.threshold,
                                    alert.unit,
                                )
                            }}
                        </div>
                        <!-- Muting and marking as handled arrive with the
                             next release. -->
                        <div
                            class="flex flex-wrap"
                            style="
                                gap: var(--nc-space-2);
                                margin-top: var(--nc-space-2);
                            "
                        >
                            <button
                                type="button"
                                class="nc-btn nc-btn-secondary"
                                style="font-size: 11px; padding: 2px 8px"
                                disabled
                                :title="$t('Available soon')"
                            >
                                <PhBellSlash :size="12" />{{ $t('Mute 1h') }}
                            </button>
                            <button
                                type="button"
                                class="nc-btn nc-btn-ghost"
                                style="font-size: 11px; padding: 2px 8px"
                                disabled
                                :title="$t('Available soon')"
                            >
                                <PhCheck :size="12" />{{ $t('Handled') }}
                            </button>
                        </div>
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
