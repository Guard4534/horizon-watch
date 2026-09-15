<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { PhKey } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EnvironmentCard from '@/components/monitoring/applications/EnvironmentCard.vue';
import EnvironmentComparison from '@/components/monitoring/applications/EnvironmentComparison.vue';
import RecentAlerts from '@/components/monitoring/applications/RecentAlerts.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCount, statusColor, statusLabel } from '@/lib/monitoring';
import { index as applicationsIndex } from '@/routes/applications';

defineOptions({
    layout: { title: 'Application detail', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationDetailPageData;
}>();

usePoll(15000, { only: ['page', 'openAlertCount'] });

const slug = useTeamSlug();
const environments = computed(() => page.cards.map((card) => card.environment));

const worst = computed(() =>
    [...environments.value].sort((a, b) => severity(a.status) - severity(b.status))[0],
);

function severity(status: App.Enums.EnvironmentStatus): number {
    return { inactive: 0, unreachable: 0, paused: 1, degraded: 2, active: 3 }[status];
}

const stats = computed(() => [
    { label: 'Environments', value: String(environments.value.length), color: 'var(--nc-text)' },
    { label: 'Nodes', value: String(environments.value.reduce((sum, environment) => sum + environment.nodeCount, 0)), color: 'var(--nc-text)' },
    { label: 'Pending', value: formatCount(environments.value.reduce((sum, environment) => sum + environment.pending, 0)), color: 'var(--nc-text)' },
    { label: 'Worst status', value: worst.value ? statusLabel(worst.value.status) : '—', color: worst.value ? statusColor(worst.value.status) : 'var(--nc-text)' },
]);
</script>

<template>
    <Head :title="page.application.name" />

    <div class="flex flex-col" style="padding: var(--nc-space-6); gap: var(--nc-space-6)">
        <div class="flex flex-wrap items-end" style="gap: var(--nc-space-6)">
            <div class="min-w-0">
                <div style="font-size: 11px; color: var(--nc-neutral-500)">
                    <Link :href="applicationsIndex(slug)">{{ $t('Applications') }}</Link>
                </div>
                <div style="font-size: 26px; line-height: 1.15">{{ page.application.name }}</div>
                <div style="font-size: 12px; color: var(--nc-neutral-500); letter-spacing: 0.01em">{{ page.application.host }}</div>
            </div>
            <div class="ml-auto flex flex-wrap" style="gap: var(--nc-space-6)">
                <div v-for="stat in stats" :key="stat.label">
                    <div class="nc-label">{{ stat.label === 'Pending' ? 'Pending' : $t(stat.label) }}</div>
                    <div class="nc-num" style="font-size: 22px; line-height: 1.2" :style="{ color: stat.color }">{{ stat.value }}</div>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: var(--nc-space-3)">
            <EnvironmentCard v-for="card in page.cards" :key="card.environment.id" :card="card" />
        </div>

        <div class="grid items-start" style="grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: var(--nc-space-6)">
            <EnvironmentComparison :environments="environments" />
            <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
                <RecentAlerts :alerts="page.recentAlerts" />
                <SectionCard :title="$t('Credentials & collection')">
                    <div style="font-size: 12px; color: var(--nc-neutral-400)">
                        {{ $t('The panel is self-hosted and polls /horizon/api of every environment every 15 seconds from inside the network. Basic-auth username and password are stored encrypted per environment and never shown in clear.') }}
                    </div>
                    <button type="button" class="nc-btn nc-btn-secondary mt-[var(--nc-space-3)]" style="font-size: 12px" disabled :title="$t('Available soon')">
                        <PhKey :size="13" />{{ $t('Manage credentials') }}
                    </button>
                </SectionCard>
            </div>
        </div>
    </div>
</template>
