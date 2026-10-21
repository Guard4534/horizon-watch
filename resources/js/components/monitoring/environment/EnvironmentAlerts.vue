<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AlertActions from '@/components/monitoring/alerts/AlertActions.vue';
import AlertMarks from '@/components/monitoring/alerts/AlertMarks.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCondition, ruleIcon, ruleLabel } from '@/lib/alertRules';
import { isMuted, severityColor } from '@/lib/alerts';
import { formatAgo } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';

const { compact = false } = defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
    compact?: boolean;
}>();

const slug = useTeamSlug();

function color(alert: App.Data.Monitoring.AlertData): string {
    return isMuted(alert)
        ? 'var(--nc-neutral-500)'
        : severityColor(alert.severity);
}
</script>

<template>
    <SectionCard :title="$t('Alerts on this environment')">
        <template #actions>
            <Link :href="alertsIndex(slug)" class="nc-t-2xs">{{
                $t('Full log')
            }}</Link>
        </template>
        <div class="flex flex-col" style="gap: var(--nc-space-3)">
            <div v-for="alert in alerts" :key="alert.id" class="live-alert">
                <div class="flex items-start gap-[9px]">
                    <component
                        :is="ruleIcon(alert.metric)"
                        :size="15"
                        class="mt-[2px] flex-none"
                        :style="{ color: color(alert) }"
                    />
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap items-baseline"
                            style="gap: 0 var(--nc-space-2)"
                        >
                            <span class="title">{{
                                ruleLabel(alert.metric)
                            }}</span>
                            <span class="nc-num nc-t-2xs nc-tone-muted">{{
                                $t('opened :elapsed', {
                                    elapsed: formatAgo(alert.minutesAgo * 60),
                                })
                            }}</span>
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
                        <AlertMarks :alert="alert" class="mt-[4px]" />
                    </div>
                    <AlertActions
                        v-if="!compact"
                        :alert="alert"
                        class="flex-none"
                    />
                </div>
                <AlertActions
                    v-if="compact"
                    :alert="alert"
                    block
                    class="mt-[var(--nc-space-2)]"
                />
            </div>
        </div>
    </SectionCard>
</template>

<style scoped>
.live-alert {
    padding: 0 0 var(--nc-space-3);
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.live-alert:last-child {
    padding-bottom: 0;
    border-bottom: 0;
}

.title {
    font-size: 13px;
    line-height: 1.35;
}
</style>
