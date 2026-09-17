<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatThreshold, ruleIcon, ruleLabel } from '@/lib/alertRules';
import { index as alertRulesIndex } from '@/routes/alert-rules';

const { rules, overrideCount, scope } = defineProps<{
    rules: App.Data.Monitoring.AlertRuleData[];
    overrideCount: number;
    scope: string;
}>();

const slug = useTeamSlug();
</script>

<template>
    <SectionCard :title="$t('Effective alert rules')">
        <template #actions>
            <Link
                :href="alertRulesIndex({ current_team: slug, scope })"
                style="font-size: 11px"
                >{{ $t('Edit') }}</Link
            >
        </template>
        <div
            class="mb-[var(--nc-space-3)]"
            style="font-size: 11px; color: var(--nc-neutral-500)"
        >
            {{
                $tChoice(
                    'Organization defaults with :count override on this environment|Organization defaults with :count overrides on this environment',
                    overrideCount,
                )
            }}
        </div>
        <div
            class="flex flex-col"
            style="gap: var(--nc-space-2); font-size: 12px"
        >
            <div
                v-for="rule in rules.slice(0, 5)"
                :key="rule.metric"
                class="flex items-center gap-2"
            >
                <component
                    :is="ruleIcon(rule.metric)"
                    :size="14"
                    class="flex-none"
                    :style="{
                        color:
                            rule.severity === 'critical'
                                ? 'var(--st-down)'
                                : 'var(--st-warn)',
                    }"
                />
                <span class="min-w-0 truncate">{{
                    ruleLabel(rule.metric)
                }}</span>
                <span
                    class="ml-auto flex-none"
                    style="letter-spacing: 0.01em; color: var(--nc-neutral-300)"
                    >{{ formatThreshold(rule.threshold, rule.unit) }}</span
                >
                <!-- "override" / "org" stay English in both languages: the mockup
                     keeps rule-origin badges untranslated on purpose. -->
                <span
                    class="nc-tag nc-tag-sm flex-none"
                    :class="
                        rule.origin === 'override'
                            ? 'nc-tag-accent'
                            : 'nc-tag-neutral'
                    "
                    >{{ rule.origin === 'override' ? 'override' : 'org' }}</span
                >
            </div>
        </div>
    </SectionCard>
</template>
