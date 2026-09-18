<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    formatThreshold,
    ruleIcon,
    ruleLabel,
    severityColor,
    severityLabel,
} from '@/lib/alertRules';
import { index as alertRulesIndex } from '@/routes/alert-rules';

const { rules, environmentName } = defineProps<{
    rules: App.Data.Monitoring.AlertRuleData[];
    environmentName: string;
}>();

const slug = useTeamSlug();

const scope = computed(() => environmentName.toLowerCase());
</script>

<template>
    <SectionCard :title="$t('Effective alert rules')">
        <template #actions>
            <Link
                :href="alertRulesIndex({ current_team: slug, scope })"
                style="font-size: 11px"
                >{{ $t('Alert settings') }}</Link
            >
        </template>
        <div
            class="mb-[var(--nc-space-3)]"
            style="font-size: 11px; color: var(--nc-neutral-500)"
        >
            {{
                $t(
                    'The organization rules, with the overrides of the :scope environments.',
                    { scope },
                )
            }}
        </div>
        <div
            class="flex flex-col"
            style="gap: var(--nc-space-2); font-size: 12px"
        >
            <div
                v-for="rule in rules"
                :key="rule.metric"
                class="flex items-center gap-2"
                :style="{ opacity: rule.enabled ? 1 : 0.55 }"
            >
                <component
                    :is="ruleIcon(rule.metric)"
                    :size="14"
                    class="flex-none"
                    :style="{
                        color: rule.enabled
                            ? severityColor(rule.severity)
                            : 'var(--nc-neutral-500)',
                    }"
                    :aria-label="severityLabel(rule.severity)"
                />
                <span class="min-w-0 truncate">{{
                    ruleLabel(rule.metric)
                }}</span>
                <span
                    class="nc-num ml-auto flex-none"
                    style="letter-spacing: 0.01em; color: var(--nc-neutral-300)"
                    >{{
                        rule.enabled
                            ? formatThreshold(rule.threshold, rule.unit)
                            : $t('Disabled')
                    }}</span
                >
                <span
                    class="nc-tag nc-tag-sm flex-none"
                    :class="
                        rule.origin === 'override'
                            ? 'nc-tag-accent'
                            : 'nc-tag-neutral'
                    "
                    >{{
                        rule.origin === 'override' ? $t('override') : $t('org')
                    }}</span
                >
            </div>
        </div>
    </SectionCard>
</template>
