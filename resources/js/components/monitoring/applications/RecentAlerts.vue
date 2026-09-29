<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCheckCircle } from '@phosphor-icons/vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { ruleIcon, ruleLabel } from '@/lib/alertRules';
import { severityColor } from '@/lib/alerts';
import { formatElapsed, statusColor } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';

defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();
</script>

<template>
    <SectionCard :title="$t('Recent alerts')">
        <template #actions>
            <Link :href="alertsIndex(slug)" class="nc-t-2xs">{{
                $t('Full log')
            }}</Link>
        </template>
        <div
            v-if="!alerts.length"
            class="nc-t-xs nc-tone-muted flex items-center gap-[9px]"
        >
            <PhCheckCircle :size="14" style="color: var(--st-ok)" />
            {{ $t('No open anomaly on the environments you watch.') }}
        </div>
        <div
            v-else
            class="nc-t-xs flex flex-col"
            style="gap: var(--nc-space-3)"
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
                                : alert.environmentStatus
                                  ? statusColor(alert.environmentStatus)
                                  : severityColor(alert.severity),
                    }"
                />
                <div class="min-w-0">
                    <div class="nc-t-xs">
                        {{
                            alert.state === 'resolved'
                                ? $t('Back within threshold · :environment', {
                                      environment: alert.environmentName,
                                  })
                                : `${ruleLabel(alert.metric)} · ${alert.environmentName}`
                        }}
                    </div>
                    <div class="nc-num nc-t-2xs nc-tone-faint mt-[2px]">
                        {{
                            alert.resolvedMinutesAgo !== null
                                ? $t('resolved :elapsed', {
                                      elapsed: formatElapsed(
                                          alert.resolvedMinutesAgo,
                                      ),
                                  })
                                : $t('opened :elapsed', {
                                      elapsed: formatElapsed(alert.minutesAgo),
                                  })
                        }}
                    </div>
                </div>
            </div>
        </div>
    </SectionCard>
</template>
