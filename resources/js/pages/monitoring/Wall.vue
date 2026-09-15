<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EnvironmentTile from '@/components/monitoring/wall/EnvironmentTile.vue';
import AnomalyList from '@/components/monitoring/wall/AnomalyList.vue';
import SentNotifications from '@/components/monitoring/wall/SentNotifications.vue';
import WallFilters from '@/components/monitoring/wall/WallFilters.vue';
import KpiCard from '@/components/nocturne/KpiCard.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import { formatCount } from '@/lib/monitoring';

defineOptions({
    layout: { title: 'Status wall', subtitle: 'state of every connected environment', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.WallPageData;
}>();

usePoll(15000, { only: ['page', 'openAlertCount'] });

const query = new URLSearchParams(window.location.search);
const filter = ref<'all' | 'problems'>(query.get('filter') === 'problems' ? 'problems' : 'all');
const environmentName = ref(query.get('environment') ?? '');
const search = ref(query.get('q') ?? '');

watch([filter, environmentName, search], () => {
    const params = new URLSearchParams();

    if (filter.value === 'problems') params.set('filter', 'problems');
    if (environmentName.value) params.set('environment', environmentName.value);
    if (search.value) params.set('q', search.value);

    const url = `${window.location.pathname}${params.size ? `?${params}` : ''}`;
    router.replace({ url, preserveState: true, preserveScroll: true });
});

const problems = computed(() => page.environments.filter((environment) => environment.status !== 'active'));

const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return (filter.value === 'problems' ? problems.value : page.environments)
        .filter((environment) => !environmentName.value || environment.name === environmentName.value)
        .filter((environment) => !needle || `${environment.applicationName} ${environment.name}`.toLowerCase().includes(needle));
});

const kpis = computed(() => [
    {
        label: 'Environments up',
        value: `${page.kpis.environmentsUp} / ${page.kpis.environmentsTotal}`,
        color: page.kpis.environmentsUp === page.kpis.environmentsTotal ? 'var(--st-ok)' : 'var(--st-warn)',
        note: 'across all applications',
    },
    {
        label: 'Anomalies',
        value: String(page.kpis.openAnomalies),
        color: page.kpis.openAnomalies ? 'var(--st-down)' : 'var(--st-ok)',
        note: 'to triage',
    },
    { label: 'Queued jobs', value: formatCount(page.kpis.pendingTotal), color: 'var(--nc-text)', note: 'sum of every queue' },
    {
        label: 'Failed · 24h',
        value: formatCount(page.kpis.failedLast24HoursTotal),
        color: page.kpis.failedLast24HoursTotal > 200 ? 'var(--st-warn)' : 'var(--nc-text)',
        note: 'last 24 hours',
    },
]);
</script>

<template>
    <Head :title="$t('Status wall')" />

    <div class="grid items-start" style="padding: var(--nc-space-6); gap: var(--nc-space-6); grid-template-columns: minmax(0, 1fr) 322px">
        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(152px, 1fr)); gap: var(--nc-space-3)">
                <KpiCard v-for="kpi in kpis" :key="kpi.label" :label="$t(kpi.label)" :value="kpi.value" :color="kpi.color" :note="$t(kpi.note)" />
            </div>

            <WallFilters
                v-model:filter="filter"
                v-model:environment-name="environmentName"
                v-model:search="search"
                :environments="page.environments"
                :problem-count="problems.length"
            />

            <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(176px, 1fr)); gap: var(--nc-space-3)">
                <EnvironmentTile v-for="environment in shown" :key="environment.id" :environment="environment" />
            </div>
        </div>

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <AnomalyList :anomalies="page.anomalies" />

            <SectionCard :title="$t('Organization throughput')">
                <TrendLine :values="page.throughput" :width="280" :height="66" fill />
                <div class="mt-[var(--nc-space-2)] flex" style="gap: var(--nc-space-4); font-size: 11px; color: var(--nc-neutral-500)">
                    <span>{{ $t(':count jobs/min now', { count: String(page.jobsPerMinute) }) }}</span>
                    <span class="ml-auto">{{ $t('last 3 hours') }}</span>
                </div>
            </SectionCard>

            <SentNotifications :notifications="page.notifications" />
        </div>
    </div>
</template>
