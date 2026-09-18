<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { PhBellSimpleSlash } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import AlertCards from '@/components/mobile/alerts/AlertCards.vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import AlertPagination from '@/components/monitoring/alerts/AlertPagination.vue';
import AlertTable from '@/components/monitoring/alerts/AlertTable.vue';
import DeliveryPolicy from '@/components/monitoring/alerts/DeliveryPolicy.vue';
import DeliveryTest from '@/components/monitoring/alerts/DeliveryTest.vue';
import EmailPreview from '@/components/monitoring/alerts/EmailPreview.vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useLivePoll } from '@/composables/useLivePoll';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { pageCount } from '@/lib/alerts';
import { index as alertsIndex } from '@/routes/alerts';

defineOptions({
    layout: { title: 'Alerts', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.AlertLogPageData;
}>();

useLivePoll(['page', 'openAlertCount']);

const isMobile = useIsMobile();
const slug = useTeamSlug();
const shared = usePage();

const nothingVisible = computed(() => page.environmentCount === 0);

const somethingIsHidden = computed(
    () =>
        shared.props.visibilityRestricted &&
        shared.props.organizationHasEnvironments,
);

function visit(next: {
    state?: App.Enums.AlertState;
    application?: string | null;
    number?: number;
}): void {
    router.cancelAll({ sync: false, prefetch: false });

    const state = next.state ?? page.state;
    const application =
        next.application === undefined ? page.application : next.application;
    const number = next.number ?? 1;
    const query: Record<string, string | number> = {};

    if (state !== 'open') query.state = state;
    if (application) query.application = application;
    if (number > 1) query.page = number;

    router.get(
        alertsIndex(slug.value, { query }).url,
        {},
        {
            only: ['page'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

const state = computed({
    get: () => page.state,
    set: (next: App.Enums.AlertState) => visit({ state: next }),
});

const application = computed({
    get: () => page.application ?? '',
    set: (next: string) => visit({ application: next || null }),
});

const pages = computed(() => pageCount(page.total, page.perPage));

const tabs = computed(() => [
    {
        value: 'open' as const,
        label: `${trans('Open alerts')} ${page.counts.open}`,
    },
    {
        value: 'muted' as const,
        label: `${trans('Muted alerts')} ${page.counts.muted}`,
    },
    {
        value: 'resolved' as const,
        label: `${trans('Resolved alerts')} ${page.counts.resolved}`,
    },
]);

const empty = computed(() => {
    if (page.application) {
        return trans('No alert of this application in this tab.');
    }

    switch (page.state) {
        case 'muted':
            return trans('No muted alerts.');
        case 'resolved':
            return trans('No resolved alerts.');
        default:
            return trans(
                'No open anomalies: every watched environment is within its thresholds.',
            );
    }
});

const previewed = computed(
    () => page.alerts.find((alert) => alert.severity === 'critical') ?? null,
);
</script>

<template>
    <Head :title="$t('Alerts')" />

    <div v-if="nothingVisible" class="page-pad">
        <EmptyState
            :icon="PhBellSimpleSlash"
            :kicker="$t('Nothing to watch')"
            :title="$t('No alerts yet')"
            :body="
                somethingIsHidden
                    ? $t(
                          'No environment is visible to you yet. Your access covers part of this organization, which may hold environments you cannot see.',
                      )
                    : shared.props.canManageApplications
                      ? $t(
                            'Configure an application with at least one environment first: alerts appear as soon as there is something to watch.',
                        )
                      : $t(
                            'Nothing is configured yet. An administrator of this organization has to add an application before anything shows up here.',
                        )
            "
        />
    </div>

    <div v-else-if="isMobile" class="flex flex-col">
        <div
            class="flex flex-col"
            style="
                gap: var(--nc-space-2);
                padding: var(--nc-space-3) var(--nc-space-4) 0;
            "
        >
            <SegmentedControl
                v-model="state"
                name="alert-state-mobile"
                class="mobile-seg"
                :options="tabs"
            />
            <select
                v-if="page.applications.length > 1 || page.application"
                v-model="application"
                class="nc-input"
                style="font-size: 12px"
                :aria-label="$t('Filter by application')"
            >
                <option value="">{{ $t('All applications') }}</option>
                <option
                    v-for="option in page.applications"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.name }}
                </option>
            </select>
        </div>
        <div
            class="flex flex-col"
            style="
                gap: var(--nc-space-3);
                padding: var(--nc-space-3) var(--nc-space-4) var(--nc-space-4);
            "
        >
            <AlertCards :alerts="page.alerts" :empty="empty" />
            <AlertPagination
                v-if="pages > 1 || page.page > 1"
                :page="page.page"
                :pages="pages"
                @go="(number) => visit({ number })"
            />
        </div>
    </div>

    <div v-else class="page-pad alerts-grid">
        <section class="nc-card min-w-0">
            <div
                class="mb-[var(--nc-space-3)] flex flex-wrap items-center"
                style="gap: var(--nc-space-3)"
            >
                <SegmentedControl
                    v-model="state"
                    name="alert-state"
                    :options="tabs"
                />
                <select
                    v-if="page.applications.length > 1 || page.application"
                    v-model="application"
                    class="nc-input ml-auto"
                    style="max-width: 230px; font-size: 12px"
                    :aria-label="$t('Filter by application')"
                >
                    <option value="">{{ $t('All applications') }}</option>
                    <option
                        v-for="option in page.applications"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }}
                    </option>
                </select>
            </div>
            <AlertTable :alerts="page.alerts" :empty="empty" />
            <AlertPagination
                v-if="pages > 1 || page.page > 1"
                class="mt-[var(--nc-space-3)]"
                :page="page.page"
                :pages="pages"
                @go="(number) => visit({ number })"
            />
        </section>

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <EmailPreview v-if="page.notifications" :alert="previewed" />
            <DeliveryTest
                v-if="page.notifications"
                :settings="page.notifications"
            />
            <DeliveryPolicy
                :summary="page.notificationSummary"
                :manages="page.notifications !== null"
            />
        </div>
    </div>
</template>

<style scoped>
.page-pad {
    padding: var(--nc-space-6);
}

.alerts-grid {
    display: grid;
    align-items: start;
    gap: var(--nc-space-6);
    grid-template-columns: minmax(0, 1fr) 300px;
}

@media (max-width: 1023px) {
    .alerts-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

.mobile-seg {
    width: 100%;
}

.mobile-seg :deep(.nc-seg-opt) {
    flex: 1;
    justify-content: center;
    padding-inline: 4px;
    font-size: 12px;
    white-space: nowrap;
}
</style>
