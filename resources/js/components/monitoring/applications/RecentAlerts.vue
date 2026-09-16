<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCheckCircle, PhWarning } from '@phosphor-icons/vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatMinutesAgo, statusColor } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';

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
                    :is="alert.state === 'resolved' ? PhCheckCircle : PhWarning"
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
                                : $t('Anomaly on :environment', {
                                      environment: alert.environmentName,
                                  })
                        }}
                    </div>
                    <div
                        class="mt-[2px]"
                        style="font-size: 11px; color: var(--nc-neutral-600)"
                    >
                        email + webhook ·
                        {{ formatMinutesAgo(alert.minutesAgo) }}
                    </div>
                </div>
            </div>
        </div>
    </SectionCard>
</template>
