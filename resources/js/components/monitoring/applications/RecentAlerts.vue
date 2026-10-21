<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCheckCircle } from '@phosphor-icons/vue';
import AlertMarks from '@/components/monitoring/alerts/AlertMarks.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { ruleLabel } from '@/lib/alertRules';
import { alertIcon, alertTone } from '@/lib/alerts';
import { formatAgo } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';

defineProps<{
    applicationId: string;
    alerts: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();
</script>

<template>
    <SectionCard :title="$t('Recent alerts')">
        <template #actions>
            <Link
                :href="
                    alertsIndex(slug, { query: { application: applicationId } })
                "
                class="nc-t-2xs"
                >{{ $t('Full log') }}</Link
            >
        </template>
        <div
            v-if="!alerts.length"
            class="nc-t-xs nc-tone-muted flex items-center gap-[9px]"
        >
            <PhCheckCircle :size="14" style="color: var(--st-ok)" />
            {{
                $t('No open or recent alerts on the environments you can see.')
            }}
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
                    :is="alertIcon(alert)"
                    :size="14"
                    class="mt-[2px] flex-none"
                    :style="{ color: alertTone(alert) }"
                />
                <div class="min-w-0">
                    <div
                        class="nc-t-xs flex flex-wrap items-center"
                        style="gap: 6px"
                    >
                        <span>{{
                            alert.state === 'resolved'
                                ? $t('Back within threshold')
                                : ruleLabel(alert.metric)
                        }}</span>
                        <EnvPill
                            :name="alert.environmentName"
                            :type="alert.environmentType"
                            :size="11"
                        />
                    </div>
                    <div class="nc-num nc-t-2xs nc-tone-faint mt-[2px]">
                        {{
                            alert.resolvedMinutesAgo !== null
                                ? $t('resolved :elapsed', {
                                      elapsed: formatAgo(
                                          alert.resolvedMinutesAgo * 60,
                                      ),
                                  })
                                : $t('opened :elapsed', {
                                      elapsed: formatAgo(alert.minutesAgo * 60),
                                  })
                        }}
                    </div>
                    <AlertMarks :alert="alert" class="mt-[3px]" />
                </div>
            </div>
        </div>
    </SectionCard>
</template>
