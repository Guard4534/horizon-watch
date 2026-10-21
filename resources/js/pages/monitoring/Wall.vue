<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PhPlus, PhStackSimple } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import MobileWall from '@/components/mobile/wall/MobileWall.vue';
import AudienceWarning from '@/components/monitoring/alerts/AudienceWarning.vue';
import VisibilityEmptyState from '@/components/monitoring/VisibilityEmptyState.vue';
import AnomalyList from '@/components/monitoring/wall/AnomalyList.vue';
import ApplicationGroup from '@/components/monitoring/wall/ApplicationGroup.vue';
import FirstRun from '@/components/monitoring/wall/FirstRun.vue';
import SentNotifications from '@/components/monitoring/wall/SentNotifications.vue';
import WallFilters from '@/components/monitoring/wall/WallFilters.vue';
import KpiCard from '@/components/nocturne/KpiCard.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import TimeSeriesChart from '@/components/nocturne/TimeSeriesChart.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useLivePoll } from '@/composables/useLivePoll';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { useVisibility } from '@/composables/useVisibility';
import { useWallKpis } from '@/composables/useWallKpis';
import {
    displayState,
    needsAttention,
} from '@/components/monitoring/environment/readings';
import { formatCount } from '@/lib/monitoring';
import { rangeLabel } from '@/lib/timeSeries';
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

useLivePoll();

const throughputLabel = computed(() =>
    trans(':range, peak :value', {
        range: rangeLabel('3h'),
        value: `${formatCount(Math.max(0, ...page.throughput))}/min`,
    }),
);

const describeThroughput = (value: number) => `${value}/min`;

const isMobile = useIsMobile();

const slug = useTeamSlug();
const shared = usePage();
const { canManageApplications, somethingIsHidden } = useVisibility();

const nothingVisible = computed(() => page.environments.length === 0);

const needsEnvironment = computed(
    () => canManageApplications.value && page.applicationCount > 0,
);

const [path, search] = shared.url.split('?');
const query = new URLSearchParams(search ?? '');
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
const typeFromQuery = (value: string | null): App.Enums.EnvironmentType | '' =>
    page.types.some((option) => option.value === value)
        ? (value as App.Enums.EnvironmentType)
        : '';
const environmentType = ref<App.Enums.EnvironmentType | ''>(
    typeFromQuery(query.get('type')),
);
const typeLabel = computed(
    () =>
        page.types.find((option) => option.value === environmentType.value)
            ?.label ?? '',
);
const needle = ref(query.get('q') ?? '');

watch([chosenFilter, environmentType, needle], () => {
    const params = new URLSearchParams();

    if (chosenFilter.value) params.set('filter', chosenFilter.value);
    if (environmentType.value) params.set('type', environmentType.value);
    if (needle.value) params.set('q', needle.value);

    const url = `${path}${params.size ? `?${params}` : ''}`;
    router.replace({ url, preserveState: true, preserveScroll: true });
});

const attention = computed(() => page.environments.filter(needsAttention));

const firstRun = computed(
    () =>
        nothingVisible.value &&
        !somethingIsHidden.value &&
        canManageApplications.value &&
        !needsEnvironment.value,
);

const waitingCount = computed(
    () =>
        page.environments.filter(
            (environment) => displayState(environment) === 'waiting',
        ).length,
);

function clearNarrowing(): void {
    environmentType.value = '';
    needle.value = '';
}

const shown = computed(() => {
    const wanted = needle.value.trim().toLowerCase();

    return (filter.value === 'problems' ? attention.value : page.environments)
        .filter(
            (environment) =>
                !environmentType.value ||
                environment.type === environmentType.value,
        )
        .filter(
            (environment) =>
                !wanted ||
                `${environment.applicationName} ${environment.name}`
                    .toLowerCase()
                    .includes(wanted),
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

const kpis = useWallKpis(() => page.kpis);
</script>

<template>
    <Head :title="$t('Status wall')" />

    <div v-if="firstRun" class="nc-page">
        <FirstRun />
    </div>

    <div v-else-if="nothingVisible" class="nc-page">
        <VisibilityEmptyState
            :icon="PhStackSimple"
            :title="$t('No environments yet')"
            :body="
                needsEnvironment
                    ? $t(
                          'An application is configured but has no environment yet. Add one to it and it shows up here.',
                      )
                    : $t(
                          'Nothing is configured yet. An administrator of this organization has to add an application before anything shows up here.',
                      )
            "
        >
            <Link
                v-if="needsEnvironment"
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
        </VisibilityEmptyState>
    </div>

    <div v-else-if="isMobile" class="nc-page">
        <AudienceWarning
            v-if="page.alertsReachNobody"
            :link="page.canManageAlertRules ? 'settings' : 'profile'"
            style="margin-bottom: var(--nc-space-3)"
        />
        <MobileWall
            v-model:filter="filter"
            :rows="shown"
            :total-count="page.environments.length"
            :attention-count="page.kpis.environmentsNeedingAttention"
            :waiting-count="waitingCount"
            :type-label="typeLabel"
            :search="needle"
            :kpis="page.kpis"
            @clear="clearNarrowing"
        />
    </div>

    <div v-else class="nc-page wall-grid">
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
                    :key="kpi.key"
                    :label="kpi.label"
                    :value="kpi.value"
                    :color="kpi.color"
                    :note="kpi.note"
                />
            </div>

            <WallFilters
                v-model:filter="filter"
                v-model:environment-type="environmentType"
                v-model:search="needle"
                :environments="page.environments"
                :types="page.types"
                :attention-count="page.kpis.environmentsNeedingAttention"
            />

            <ApplicationGroup
                v-for="group in groups"
                :key="group.id"
                v-model:expanded="openGroups[group.id]"
                :application-id="group.id"
                :application-name="group.name"
                :environments="group.environments"
            />
            <p v-if="groups.length === 0" class="nc-t-sm nc-tone-muted">
                {{
                    filter !== 'problems' || environmentType || needle.trim()
                        ? $t('No environment matches these filters.')
                        : waitingCount > 0
                          ? $t(
                                'Nothing needs attention. Some environments have no reading yet.',
                            )
                          : $t('Nothing needs attention.')
                }}
            </p>
        </div>

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <AudienceWarning
                v-if="page.alertsReachNobody"
                :link="page.canManageAlertRules ? 'settings' : 'profile'"
            />

            <AnomalyList :anomalies="page.anomalies" />

            <SectionCard :title="$t('Organization throughput')">
                <TimeSeriesChart
                    :values="page.throughput"
                    :starts-at="page.grid.startsAt"
                    :step-seconds="page.grid.stepSeconds"
                    :height="110"
                    :label="throughputLabel"
                    :describe="describeThroughput"
                />
                <div
                    class="nc-t-2xs nc-tone-muted mt-[var(--nc-space-2)] flex"
                    style="gap: var(--nc-space-4)"
                >
                    <span>{{
                        $t(':value now', {
                            value: `${page.jobsPerMinute}/min`,
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
</style>
