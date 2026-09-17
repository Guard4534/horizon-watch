<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCheckCircle } from '@phosphor-icons/vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { ruleIcon, ruleLabel } from '@/lib/alertRules';
import { formatAge } from '@/components/monitoring/environment/readings';
import { statusColor } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';

// Open anomalies of this application's watched environments; resolved
// ones join with phase 4. Nothing is sent yet, so no channel is named.
defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();
</script>

<template>
    <SectionCard :title="$t('Recent alerts')">
        <template #actions>
            <Link :href="alertsIndex(slug)" style="font-size: 11px">{{
                $t('Full log')
            }}</Link>
        </template>
        <div
            v-if="!alerts.length"
            class="flex items-center gap-[9px]"
            style="font-size: 12px; color: var(--nc-neutral-500)"
        >
            <PhCheckCircle :size="14" style="color: var(--st-ok)" />
            {{ $t('No open anomaly on the environments you watch.') }}
        </div>
        <div
            v-else
            class="flex flex-col"
            style="gap: var(--nc-space-3); font-size: 12px"
        >
            <div
                v-for="alert in alerts"
                :key="alert.id"
                class="flex items-start gap-[9px]"
                style="
                    padding-bottom: var(--nc-space-2);
                    border-bottom: 1px solid
                        color-mix(in srgb, var(--nc-text) 7%, transparent);
                "
            >
                <component
                    :is="
                        alert.state === 'resolved'
                            ? PhCheckCircle
                            : ruleIcon(alert.metric)
                    "
                    :size="14"
                    class="mt-[2px] flex-none"
                    :style="{
                        color:
                            alert.state === 'resolved'
                                ? 'var(--st-ok)'
                                : statusColor(alert.environmentStatus),
                    }"
                />
                <div class="min-w-0">
                    <div style="font-size: 12px">
                        {{
                            alert.state === 'resolved'
                                ? $t('Back within threshold · :environment', {
                                      environment: alert.environmentName,
                                  })
                                : `${ruleLabel(alert.metric)} · ${alert.environmentName}`
                        }}
                    </div>
                    <div
                        class="nc-num mt-[2px]"
                        style="font-size: 11px; color: var(--nc-neutral-600)"
                    >
                        {{
                            alert.sinceTruncated
                                ? $t('open for more than 24 h')
                                : $t('open for :time', {
                                      time: formatAge(alert.minutesAgo * 60),
                                  })
                        }}
                    </div>
                </div>
            </div>
        </div>
    </SectionCard>
</template>
