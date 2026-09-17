<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    PhBellSlash,
    PhCheck,
    PhWarning,
    PhWarningOctagon,
} from '@phosphor-icons/vue';
import AlertOpened from '@/components/monitoring/alerts/AlertOpened.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { ruleLabel } from '@/lib/alertRules';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    alerts: App.Data.Monitoring.AlertData[];
    empty: string;
}>();

const slug = useTeamSlug();

function openCard(
    alert: App.Data.Monitoring.AlertData,
    event: MouseEvent,
): void {
    if ((event.target as HTMLElement).closest('a, button')) {
        return;
    }

    router.visit(
        showEnvironment({
            current_team: slug.value,
            environment: alert.environmentId,
        }).url,
    );
}
</script>

<template>
    <div class="flex flex-col" style="gap: var(--nc-space-2)">
        <div
            v-if="alerts.length === 0"
            class="nc-card"
            style="font-size: 12px; color: var(--nc-neutral-500)"
        >
            {{ empty }}
        </div>
        <div
            v-for="alert in alerts"
            :key="alert.id"
            class="alert-card"
            :class="{ 'is-critical': alert.severity === 'critical' }"
            style="cursor: pointer"
            @click="openCard(alert, $event)"
        >
            <div class="flex items-start gap-[9px]">
                <component
                    :is="
                        alert.severity === 'critical'
                            ? PhWarningOctagon
                            : PhWarning
                    "
                    :size="15"
                    class="mt-px flex-none"
                    :style="{
                        color:
                            alert.severity === 'critical'
                                ? 'var(--st-down)'
                                : 'var(--st-warn)',
                    }"
                />
                <div class="min-w-0 flex-1">
                    <!-- The title opens the environment: the only action
                         that works before the next release. -->
                    <Link
                        :href="
                            showEnvironment({
                                current_team: slug,
                                environment: alert.environmentId,
                            })
                        "
                        class="block"
                        style="
                            font-size: 12px;
                            line-height: 1.35;
                            color: inherit;
                            text-decoration: none;
                        "
                        >{{ ruleLabel(alert.metric) }}</Link
                    >
                    <div
                        class="mt-[4px] flex items-center gap-[6px]"
                        style="font-size: 10px; color: var(--nc-neutral-500)"
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
                        <span class="ml-auto flex-none"
                            ><AlertOpened :alert="alert"
                        /></span>
                    </div>
                    <div class="mt-[8px] flex gap-[6px]">
                        <button
                            type="button"
                            class="nc-btn nc-btn-secondary card-action"
                            disabled
                            :title="$t('Available soon')"
                        >
                            <PhBellSlash :size="12" />
                            {{ $t('Mute 1h') }}
                        </button>
                        <button
                            type="button"
                            class="nc-btn nc-btn-ghost card-action"
                            disabled
                            :title="$t('Available soon')"
                        >
                            <PhCheck :size="12" />
                            {{ $t('Handled') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.alert-card {
    padding: var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
}

.alert-card.is-critical {
    background: color-mix(in srgb, var(--st-down) 12%, var(--nc-surface));
}

.card-action {
    flex: 1;
    justify-content: center;
    font-size: 11px;
    padding: 3px 8px;
}
</style>
