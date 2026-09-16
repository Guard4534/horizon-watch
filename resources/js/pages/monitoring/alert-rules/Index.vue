<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { PhSlidersHorizontal } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import NotificationSettings from '@/components/monitoring/rules/NotificationSettings.vue';
import RuleRow from '@/components/monitoring/rules/RuleRow.vue';
import ScopeList from '@/components/monitoring/rules/ScopeList.vue';

defineOptions({
    layout: { title: 'Alert settings' },
});

const { page } = defineProps<{
    page: App.Data.Pages.AlertRulesPageData;
}>();

const organization = computed(() => page.scope === 'organization');

const shared = usePage();

// The organization scope counts every visible environment (see
// MonitoringRepository::ruleScopes): zero means there are no thresholds
// worth showing, since there is nothing they could apply to.
const nothingVisible = computed(
    () =>
        page.scopes.find((scope) => scope.id === 'organization')
            ?.environmentCount === 0,
);
</script>

<template>
    <Head :title="$t('Alert settings')" />

    <div v-if="nothingVisible" style="padding: var(--nc-space-6)">
        <EmptyState
            :icon="PhSlidersHorizontal"
            :kicker="$t('Nothing to watch')"
            :title="$t('No thresholds yet')"
            :body="
                shared.props.canManageApplications
                    ? $t(
                          'Configure an application with at least one environment first: its thresholds can be reviewed here afterwards.',
                      )
                    : $t(
                          'No environment is visible to you yet. An administrator of this organization can widen your visibility or configure an application.',
                      )
            "
        />
    </div>

    <div
        v-else
        class="grid items-start"
        style="
            padding: var(--nc-space-6);
            gap: var(--nc-space-6);
            grid-template-columns: 224px minmax(0, 1fr);
        "
    >
        <ScopeList :scopes="page.scopes" :current="page.scope" />

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <section class="nc-card">
                <div
                    class="flex flex-wrap items-start"
                    style="
                        gap: var(--nc-space-3);
                        margin-bottom: var(--nc-space-4);
                    "
                >
                    <div class="min-w-0">
                        <div style="font-size: 17px">
                            {{
                                organization
                                    ? $t('Default thresholds')
                                    : $t('Override · :scope environments', {
                                          scope: page.scope,
                                      })
                            }}
                        </div>
                        <div
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-500);
                            "
                        >
                            {{
                                organization
                                    ? $t(
                                          'Apply to every environment without an override',
                                      )
                                    : $t(
                                          'Override the organization defaults for these environments only',
                                      )
                            }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="nc-btn nc-btn-ghost ml-auto"
                        style="font-size: 12px"
                        disabled
                        :title="$t('Available soon')"
                    >
                        {{
                            organization
                                ? $t('Reset to recommended')
                                : $t('Remove all overrides')
                        }}
                    </button>
                </div>
                <div class="flex flex-col" style="gap: var(--nc-space-3)">
                    <RuleRow
                        v-for="rule in page.rules"
                        :key="rule.metric"
                        :rule="rule"
                        :organization-scope="organization"
                    />
                </div>
            </section>

            <NotificationSettings :settings="page.notifications" />
        </div>
    </div>
</template>
