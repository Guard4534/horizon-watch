<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PhPlus, PhStackSimple } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import MobileWall from '@/components/mobile/wall/MobileWall.vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import AnomalyList from '@/components/monitoring/wall/AnomalyList.vue';
import ApplicationGroup from '@/components/monitoring/wall/ApplicationGroup.vue';
import FirstRun from '@/components/monitoring/wall/FirstRun.vue';
import SentNotifications from '@/components/monitoring/wall/SentNotifications.vue';
import WallFilters from '@/components/monitoring/wall/WallFilters.vue';
import KpiCard from '@/components/nocturne/KpiCard.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useLivePoll } from '@/composables/useLivePoll';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { failedLabel, failedWindowNote } from '@/lib/failedWindow';
import { formatCount } from '@/lib/monitoring';
import {
    create as createApplication,
    index as applicationsIndex,
} from '@/routes/applications';

defineOptions({
    layout: {
        title: 'Status wall',
        subtitle: 'state of every connected environment',
        live: true,
    },
});

const { page } = defineProps<{
    page: App.Data.Pages.WallPageData;
}>();

useLivePoll(['page', 'openAlertCount']);

const isMobile = useIsMobile();

const slug = useTeamSlug();
const shared = usePage();

const nothingVisible = computed(() => page.environments.length === 0);
const canManageApplications = computed(
    () => shared.props.canManageApplications,
);
const somethingIsHidden = computed(
    () =>
        shared.props.visibilityRestricted &&
        shared.props.organizationHasEnvironments,
);

const needsEnvironment = computed(
    () => canManageApplications.value && page.applicationCount > 0,
);

const query = new URLSearchParams(window.location.search);
const chosenFilter = ref<'all' | 'problems' | null>(
    query.get('filter') === 'problems' || query.get('filter') === 'all'
        ? (query.get('filter') as 'all' | 'problems')
        : null,
);
const filter = computed<'all' | 'problems'>({
    get: () => chosenFilter.value ?? (isMobile.value ? 'problems' : 'all'),
    set: (value) => {
        chosenFilter.value = value;
    },
});
const environmentName = ref(query.get('environment') ?? '');
const search = ref(query.get('q') ?? '');

watch([chosenFilter, environmentName, search], () => {
    const params = new URLSearchParams();

    if (chosenFilter.value) params.set('filter', chosenFilter.value);
    if (environmentName.value) params.set('environment', environmentName.value);
    if (search.value) params.set('q', search.value);

    const url = `${window.location.pathname}${params.size ? `?${params}` : ''}`;
    router.replace({ url, preserveState: true, preserveScroll: true });
});

const problems = computed(() =>
    page.environments.filter(
        (environment) =>
            environment.status !== null && environment.status !== 'active',
    ),
);

const firstRun = computed(
    () =>
        nothingVisible.value &&
        !somethingIsHidden.value &&
        canManageApplications.value &&
        !needsEnvironment.value,
);

const waitingCount = computed(
    () =>
        page.environments.filter((environment) => environment.status === null)
            .length,
);

function clearNarrowing(): void {
    environmentName.value = '';
    search.value = '';
}

const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return (filter.value === 'problems' ? problems.value : page.environments)
        .filter(
            (environment) =>
                !environmentName.value ||
                environment.name === environmentName.value,
        )
        .filter(
            (environment) =>
                !needle ||
                `${environment.applicationName} ${environment.name}`
                    .toLowerCase()
                    .includes(needle),
        );
});

const groups = computed(() => {
    const byApplication = new Map<
        string,
        {
            id: string;
            name: string;
            environments: App.Data.Monitoring.EnvironmentData[];
        }
    >();

    for (const environment of shown.value) {
        const group = byApplication.get(environment.applicationId) ?? {
            id: environment.applicationId,
            name: environment.applicationName,
            environments: [],
        };

        group.environments.push(environment);
        byApplication.set(environment.applicationId, group);
    }

    return [...byApplication.values()];
});

const openGroups = ref<Record<string, boolean | undefined>>({});

const kpis = computed(() => [
    {
        label: trans('Environments up'),
        value: `${page.kpis.environmentsUp} / ${page.kpis.environmentsTotal}`,
        color:
            page.kpis.environmentsUp + waitingCount.value ===
            page.kpis.environmentsTotal
                ? 'var(--st-ok)'
                : 'var(--st-warn)',
        note: trans('across all applications'),
    },
    {
        label: trans('Anomalies'),
        value: String(page.kpis.openAnomalies),
        color: page.kpis.openAnomalies ? 'var(--st-down)' : 'var(--st-ok)',
        note: trans('to triage'),
    },
    {
        label: trans('Queued jobs'),
        value: formatCount(page.kpis.pendingTotal),
        color: 'var(--nc-text)',
        note: trans('sum of every queue'),
    },
    {
        label: failedLabel(page.kpis.failedWindowMinutes),
        value: formatCount(page.kpis.failedTotal),
        color: page.kpis.environmentsOverFailedRate
            ? 'var(--st-warn)'
            : 'var(--nc-text)',
        note: failedWindowNote(page.kpis.failedWindowMinutes),
    },
]);
</script>

<template>
    <Head :title="$t('Status wall')" />

    <div v-if="firstRun" class="wall-pad">
        <FirstRun />
    </div>

    <div v-else-if="nothingVisible" class="wall-pad">
        <EmptyState
            :icon="PhStackSimple"
            :kicker="$t('Nothing connected')"
            :title="$t('No environments yet')"
            :body="
                somethingIsHidden
                    ? $t(
                          'No environment is visible to you yet. Your access covers part of this organization, which may hold environments you cannot see.',
                      )
                    : needsEnvironment
                      ? $t(
                            'An application is configured but has no environment yet. Add one to it and it shows up here.',
                        )
                      : $t(
                            'Nothing is configured yet. An administrator of this organization has to add an application before anything shows up here.',
                        )
            "
        >
            <Link
                v-if="!somethingIsHidden && needsEnvironment"
                class="nc-btn nc-btn-primary"
                style="margin-top: var(--nc-space-2)"
                :href="applicationsIndex(slug)"
            >
                <PhPlus :size="14" />{{ $t('Add environment') }}
            </Link>
            <Link
                v-else-if="canManageApplications"
                class="nc-btn nc-btn-primary"
                style="margin-top: var(--nc-space-2)"
                :href="createApplication(slug)"
            >
                <PhPlus :size="14" />{{ $t('Add application') }}
            </Link>
        </EmptyState>
    </div>

    <div v-else-if="isMobile" class="wall-pad">
        <MobileWall
            v-model:filter="filter"
            :rows="shown"
            :total-count="page.environments.length"
            :problem-count="problems.length"
            :waiting-count="waitingCount"
            :environment-name="environmentName"
            :search="search"
            :kpis="page.kpis"
            @clear="clearNarrowing"
        />
    </div>

    <div v-else class="wall-pad wall-grid">
        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <div
                class="grid"
                style="
                    grid-template-columns: repeat(auto-fit, minmax(152px, 1fr));
                    gap: var(--nc-space-3);
                "
            >
                <KpiCard
                    v-for="kpi in kpis"
                    :key="kpi.label"
                    :label="kpi.label"
                    :value="kpi.value"
                    :color="kpi.color"
                    :note="kpi.note"
                />
            </div>

            <WallFilters
                v-model:filter="filter"
                v-model:environment-name="environmentName"
                v-model:search="search"
                :environments="page.environments"
                :problem-count="problems.length"
            />

            <ApplicationGroup
                v-for="group in groups"
                :key="group.id"
                v-model:expanded="openGroups[group.id]"
                :application-id="group.id"
                :application-name="group.name"
                :environments="group.environments"
            />
            <p
                v-if="groups.length === 0"
                style="font-size: 13px; color: var(--nc-neutral-500)"
            >
                {{ $t('No environment matches these filters.') }}
            </p>
        </div>

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <AnomalyList :anomalies="page.anomalies" />

            <SectionCard :title="$t('Organization throughput')">
                <TrendLine
                    :values="page.throughput"
                    :width="280"
                    :height="66"
                    fill
                />
                <div
                    class="mt-[var(--nc-space-2)] flex"
                    style="
                        gap: var(--nc-space-4);
                        font-size: 11px;
                        color: var(--nc-neutral-500);
                    "
                >
                    <span>{{
                        $t(':count jobs/min now', {
                            count: String(page.jobsPerMinute),
                        })
                    }}</span>
                    <span class="ml-auto">{{ $t('last 3 hours') }}</span>
                </div>
            </SectionCard>

            <SentNotifications :notifications="page.notifications" />
        </div>
    </div>
</template>

<style scoped>
.wall-pad {
    padding: var(--nc-space-6);
}

.wall-grid {
    display: grid;
    align-items: start;
    gap: var(--nc-space-6);
    grid-template-columns: minmax(0, 1fr) 322px;
}

@media (max-width: 1023px) {
    .wall-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 639px) {
    .wall-pad {
        padding: var(--nc-space-3) var(--nc-space-4) var(--nc-space-4);
    }
}
</style>
