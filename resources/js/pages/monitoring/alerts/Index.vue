<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { PhBellSimpleSlash } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import AlertCards from '@/components/mobile/alerts/AlertCards.vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import AlertTable from '@/components/monitoring/alerts/AlertTable.vue';
import DeliveryTest from '@/components/monitoring/alerts/DeliveryTest.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useLivePoll } from '@/composables/useLivePoll';
import { ruleLabel } from '@/lib/alertRules';

defineOptions({
    layout: { title: 'Alerts', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.AlertLogPageData;
}>();

useLivePoll(['page', 'openAlertCount']);

const isMobile = useIsMobile();

const shared = usePage();

// No visible environment at all: the three tabs and the delivery policy have
// nothing to describe, so the page is just the explanation.
const nothingVisible = computed(() => page.environmentCount === 0);

// Restricted only means something is being kept from this member if the
// organization holds anything at all: a viewer limited to non-production
// in an empty organization has nothing hidden from them.
const somethingIsHidden = computed(
    () =>
        shared.props.visibilityRestricted &&
        shared.props.organizationHasEnvironments,
);

const state = computed({
    get: () => page.state,
    set: (next: App.Enums.AlertState) =>
        // ReloadOptions omits preserveScroll/preserveState from Inertia 3's Visit type: reload() already
        // preserves both, so the flag from the brief is redundant and does not type-check here.
        router.reload({ data: { state: next }, only: ['page'] }),
});

// Muted and resolved stay empty until muting and resolving exist.
const notYet = computed(() => page.state !== 'open');

const search = ref('');

const alerts = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle
        ? page.alerts.filter((alert) =>
              [
                  alert.applicationName,
                  alert.environmentName,
                  alert.metric,
                  trans(ruleLabel(alert.metric)),
              ]
                  .join(' ')
                  .toLowerCase()
                  .includes(needle),
          )
        : page.alerts;
});

const empty = computed(() =>
    search.value.trim() && page.alerts.length
        ? trans('No open anomaly matches this filter.')
        : trans(
              'No open anomalies: every watched environment is within its thresholds.',
          ),
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
            class="flex items-center gap-2"
            style="
                padding: var(--nc-space-3) var(--nc-space-4);
                border-bottom: 1px solid var(--nc-divider);
            "
        >
            <span style="font-size: 14px">{{ $t('Alerts') }}</span>
            <span
                class="ml-auto"
                style="font-size: 10px; color: var(--nc-neutral-500)"
                >{{
                    $t(':count open', { count: String(page.counts.open) })
                }}</span
            >
        </div>
        <div style="padding: var(--nc-space-3) var(--nc-space-4) 0">
            <SegmentedControl
                v-model="state"
                name="alert-state-mobile"
                class="mobile-seg"
                :options="[
                    { value: 'open', label: $t('Open') },
                    { value: 'muted', label: $t('Muted') },
                    { value: 'resolved', label: $t('Resolved') },
                ]"
            />
        </div>
        <div
            style="
                padding: var(--nc-space-3) var(--nc-space-4) var(--nc-space-4);
            "
        >
            <div
                v-if="notYet"
                class="nc-card"
                style="font-size: 12px; color: var(--nc-neutral-400)"
            >
                {{ $t('Muting and resolving arrive with the next release.') }}
            </div>
            <AlertCards v-else :alerts="page.alerts" :empty="empty" />
        </div>
    </div>

    <div v-else class="page-pad alerts-grid">
        <section class="nc-card">
            <div
                class="mb-[var(--nc-space-3)] flex flex-wrap items-center"
                style="gap: var(--nc-space-3)"
            >
                <SegmentedControl
                    v-model="state"
                    name="alert-state"
                    :options="[
                        {
                            value: 'open',
                            label: `${$t('Open alerts')} ${page.counts.open}`,
                        },
                        {
                            value: 'muted',
                            label: `${$t('Muted alerts')} ${page.counts.muted}`,
                        },
                        {
                            value: 'resolved',
                            label: `${$t('Resolved alerts')} ${page.counts.resolved}`,
                        },
                    ]"
                />
                <input
                    v-if="!notYet"
                    v-model="search"
                    class="nc-input ml-auto"
                    style="max-width: 230px"
                    :placeholder="$t('Filter by application')"
                />
            </div>
            <div
                v-if="notYet"
                style="
                    font-size: 12px;
                    color: var(--nc-neutral-400);
                    padding: var(--nc-space-3) 0;
                "
            >
                {{ $t('Muting and resolving arrive with the next release.') }}
            </div>
            <AlertTable v-else :alerts="alerts" :empty="empty" />
        </section>

        <div class="flex flex-col" style="gap: var(--nc-space-4)">
            <DeliveryTest :settings="page.notifications" />
            <SectionCard :title="$t('Delivery policy')">
                <div style="font-size: 12px; color: var(--nc-neutral-400)">
                    {{
                        $t(
                            'A critical alert repeats every 30 minutes until it clears or gets muted. Warnings are grouped into a digest every 15 minutes. During quiet hours only criticals get through.',
                        )
                    }}
                </div>
            </SectionCard>
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

/* Between the mobile breakpoint and a laptop, the side column goes below. */
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
    font-size: 12px;
}
</style>
