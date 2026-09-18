<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { PhSlidersHorizontal } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import NotificationSettings from '@/components/monitoring/rules/NotificationSettings.vue';
import RulesForm from '@/components/monitoring/rules/RulesForm.vue';
import ScopeList from '@/components/monitoring/rules/ScopeList.vue';

defineOptions({
    layout: { title: 'Alert settings' },
});

const { page } = defineProps<{
    page: App.Data.Pages.AlertRulesPageData;
}>();

const shared = usePage();

const currentScope = computed(() =>
    page.scopes.find((scope) => scope.id === page.scope),
);

const nothingVisible = computed(
    () =>
        page.scopes.find((scope) => scope.id === 'organization')
            ?.environmentCount === 0,
);

const somethingIsHidden = computed(
    () =>
        shared.props.visibilityRestricted &&
        shared.props.organizationHasEnvironments,
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
                somethingIsHidden
                    ? $t(
                          'No environment is visible to you yet. Your access covers part of this organization, which may hold environments you cannot see.',
                      )
                    : shared.props.canManageApplications
                      ? $t(
                            'Configure an application with at least one environment first: its thresholds can be reviewed here afterwards.',
                        )
                      : $t(
                            'Nothing is configured yet. An administrator of this organization has to add an application before anything shows up here.',
                        )
            "
        />
    </div>

    <div v-else class="rules-grid">
        <ScopeList :scopes="page.scopes" :current="page.scope" />

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <RulesForm
                :key="page.scope"
                :scope="page.scope"
                :rules="page.rules"
                :organization-rules="page.organizationRules"
                :can-manage="page.canManage"
                :override-count="currentScope?.overrideCount ?? 0"
            />

            <NotificationSettings
                :summary="page.notificationSummary"
                :settings="page.notifications"
                :new-webhook-secret="page.newWebhookSecret"
                :repeat-choices="page.repeatChoices"
                :max-recipients="page.maxRecipients"
            />
        </div>
    </div>
</template>

<style scoped>
.rules-grid {
    display: grid;
    align-items: start;
    padding: var(--nc-space-6);
    gap: var(--nc-space-6);
    grid-template-columns: 224px minmax(0, 1fr);
}

@media (max-width: 767px) {
    .rules-grid {
        grid-template-columns: minmax(0, 1fr);
        padding: var(--nc-space-4);
        gap: var(--nc-space-4);
    }
}
</style>
