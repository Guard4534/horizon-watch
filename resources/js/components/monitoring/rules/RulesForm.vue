<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RuleRow from '@/components/monitoring/rules/RuleRow.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTeamSlug } from '@/composables/useTeamSlug';
import type { RuleField } from '@/lib/alertRules';
import {
    overriddenFieldCount,
    RULE_FIELDS,
    ruleFields,
} from '@/lib/alertRules';
import {
    reset as resetRules,
    update as updateRules,
} from '@/routes/alert-rules';

const { scope, rules, organizationRules, canManage, overrideCount } =
    defineProps<{
        scope: string;
        rules: App.Data.Monitoring.AlertRuleData[];
        organizationRules: App.Data.Monitoring.AlertRuleData[];
        canManage: boolean;
        overrideCount: number;
    }>();

const slug = useTeamSlug();

const organization = computed(() => scope === 'organization');

const inherited = computed(
    () =>
        new Map(organizationRules.map((rule) => [rule.metric, rule] as const)),
);

function received(): App.Data.Alerts.AlertRulesInputData {
    return {
        rules: rules.map((rule) => ruleFields(rule, organization.value)),
    };
}

const form = useForm<App.Data.Alerts.AlertRulesInputData>(received());

function adoptReceived(): void {
    form.defaults(received());
    form.reset();
    form.clearErrors();
}

watch(() => JSON.stringify(received()), adoptReceived);

function errorsOf(index: number): Partial<Record<RuleField, string>> {
    const errors = form.errors as Record<string, string | undefined>;

    return Object.fromEntries(
        RULE_FIELDS.map((field) => [field, errors[`rules.${index}.${field}`]]),
    );
}

const generalErrors = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return [
        ...new Set(
            Object.entries(errors)
                .filter(
                    ([key, message]) =>
                        message !== undefined &&
                        !/^rules\.\d+\.(threshold|severity|notifyByEmail|enabled)$/.test(
                            key,
                        ),
                )
                .map(([, message]) => message as string),
        ),
    ];
});

const pendingOverrides = computed(() =>
    organization.value
        ? 0
        : form.rules.reduce(
              (total, fields) => total + overriddenFieldCount(fields),
              0,
          ),
);

function save(): void {
    form.put(updateRules({ current_team: slug.value, scope }).url, {
        preserveScroll: true,
        onSuccess: adoptReceived,
    });
}

function cancel(): void {
    form.reset();
    form.clearErrors();
}

const confirmingReset = ref(false);
const resetting = ref(false);

function confirmReset(): void {
    router.delete(resetRules({ current_team: slug.value, scope }).url, {
        preserveScroll: true,
        onStart: () => (resetting.value = true),
        onFinish: () => {
            resetting.value = false;
            confirmingReset.value = false;
        },
    });
}
</script>

<template>
    <section class="nc-card">
        <div
            class="flex flex-wrap items-start"
            style="gap: var(--nc-space-3); margin-bottom: var(--nc-space-4)"
        >
            <div class="min-w-0">
                <div style="font-size: 17px">
                    {{
                        organization
                            ? $t('Default thresholds')
                            : $t('Override · :scope environments', {
                                  scope,
                              })
                    }}
                </div>
                <div style="font-size: 12px; color: var(--nc-neutral-500)">
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
                v-if="canManage"
                type="button"
                class="nc-btn nc-btn-ghost ml-auto"
                style="font-size: 12px"
                :disabled="
                    form.processing ||
                    resetting ||
                    (!organization && overrideCount === 0)
                "
                @click="confirmingReset = true"
            >
                {{
                    organization
                        ? $t('Reset to recommended')
                        : $t('Remove all overrides')
                }}
            </button>
        </div>

        <div
            v-if="canManage && !organization"
            class="mb-[var(--nc-space-3)]"
            style="font-size: 11px; color: var(--nc-neutral-500)"
        >
            {{
                $t(
                    'Dashed values come from the organization: use the pencil to override one, the arrow to inherit it again.',
                )
            }}
        </div>

        <div
            v-if="generalErrors.length"
            class="mb-[var(--nc-space-3)]"
            role="alert"
            style="
                border-radius: var(--nc-radius-md);
                border: 1px solid var(--st-down);
                padding: 8px 10px;
                font-size: 12px;
                color: var(--st-down);
            "
        >
            <div v-for="message in generalErrors" :key="message">
                {{ message }}
            </div>
        </div>

        <form
            class="flex flex-col"
            style="gap: var(--nc-space-3)"
            novalidate
            @submit.prevent="save"
        >
            <RuleRow
                v-for="(rule, index) in rules"
                :key="rule.metric"
                :model-value="
                    form.rules[index] ?? ruleFields(rule, organization)
                "
                @update:model-value="(fields) => (form.rules[index] = fields)"
                :rule="rule"
                :inherited="
                    organization ? null : (inherited.get(rule.metric) ?? rule)
                "
                :editable="canManage"
                :errors="errorsOf(index)"
            />

            <div
                v-if="canManage"
                class="flex flex-wrap items-center justify-end"
                style="gap: var(--nc-space-2)"
            >
                <span
                    v-if="!organization"
                    class="nc-num mr-auto"
                    style="font-size: 11px; color: var(--nc-neutral-500)"
                    >{{
                        $tChoice(
                            ':count value overridden|:count values overridden',
                            pendingOverrides,
                        )
                    }}</span
                >
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    :disabled="!form.isDirty || form.processing"
                    @click="cancel"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="submit"
                    class="nc-btn nc-btn-primary"
                    :disabled="!form.isDirty || form.processing"
                >
                    {{ $t('Save') }}
                </button>
            </div>
        </form>
    </section>

    <Dialog
        :open="confirmingReset"
        @update:open="(open) => !open && (confirmingReset = false)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    organization
                        ? $t('Reset to recommended')
                        : $t('Remove all overrides')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        organization
                            ? $t(
                                  'Every organization rule goes back to the recommended threshold, severity and email. Environment overrides stay as they are.',
                              )
                            : $t(
                                  'The :scope environments go back to the organization values for every rule.',
                                  { scope },
                              )
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    @click="confirmingReset = false"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    :disabled="resetting"
                    @click="confirmReset"
                >
                    {{
                        organization
                            ? $t('Reset to recommended')
                            : $t('Remove all overrides')
                    }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
