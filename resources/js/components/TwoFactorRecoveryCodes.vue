<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { PhArrowsClockwise, PhEye, PhEyeSlash } from '@phosphor-icons/vue';
import { nextTick, onUnmounted, ref, useTemplateRef } from 'vue';
import FormErrors from '@/components/FormErrors.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const confirmingRegeneration = ref<boolean>(false);
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const hideRecoveryCodes = () => {
    isRecoveryCodesVisible.value = false;
    confirmingRegeneration.value = false;
    recoveryCodesList.value = [];
};

const toggleRecoveryCodesVisibility = async () => {
    if (isRecoveryCodesVisible.value) {
        hideRecoveryCodes();

        return;
    }

    isRecoveryCodesVisible.value = true;
    await fetchRecoveryCodes();

    if (!isRecoveryCodesVisible.value) {
        recoveryCodesList.value = [];

        return;
    }

    await nextTick();
    recoveryCodeSectionRef.value?.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
    });
};

const regenerated = async () => {
    confirmingRegeneration.value = false;
    await fetchRecoveryCodes();
};

onUnmounted(hideRecoveryCodes);
</script>

<template>
    <SectionCard :title="$t('2FA recovery codes')" class="w-full">
        <p class="nc-t-sm nc-tone-muted">
            {{
                $t(
                    'Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.',
                )
            }}
        </p>
        <div
            class="mt-4 flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between"
        >
            <button
                type="button"
                class="nc-btn nc-btn-primary w-fit"
                @click="toggleRecoveryCodesVisibility"
            >
                <component
                    :is="isRecoveryCodesVisible ? PhEyeSlash : PhEye"
                    :size="16"
                />
                {{
                    isRecoveryCodesVisible
                        ? $t('Hide recovery codes')
                        : $t('View recovery codes')
                }}
            </button>

            <button
                v-if="
                    isRecoveryCodesVisible &&
                    recoveryCodesList.length &&
                    !confirmingRegeneration
                "
                type="button"
                class="nc-btn nc-btn-ghost nc-tone-down w-fit"
                @click="confirmingRegeneration = true"
            >
                <PhArrowsClockwise :size="16" /> {{ $t('Regenerate codes') }}
            </button>
        </div>
        <div
            v-if="confirmingRegeneration"
            class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            style="
                padding: var(--nc-space-4);
                border: 1px solid var(--nc-divider);
                border-radius: var(--nc-radius-md);
            "
        >
            <p class="nc-t-sm">
                {{
                    $t(
                        'The codes you have now stop working at once, and new ones replace them.',
                    )
                }}
            </p>
            <Form
                v-bind="regenerateRecoveryCodes.form()"
                method="post"
                :options="{ preserveScroll: true }"
                class="nc-actions flex-none"
                @success="regenerated"
                #default="{ processing }"
            >
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    :disabled="processing"
                    @click="confirmingRegeneration = false"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="submit"
                    class="nc-btn nc-btn-danger"
                    :disabled="processing"
                >
                    <PhArrowsClockwise :size="16" />
                    {{ $t('Regenerate codes') }}
                </button>
            </Form>
        </div>
        <div v-if="isRecoveryCodesVisible">
            <div v-if="errors?.length" class="mt-6">
                <FormErrors
                    :errors="errors"
                    :title="$t('Something went wrong.')"
                />
            </div>
            <div v-else class="mt-3 space-y-3">
                <div
                    ref="recoveryCodeSectionRef"
                    class="nc-code nc-t-sm grid gap-1"
                    style="
                        padding: var(--nc-space-4);
                        border-radius: var(--nc-radius-md);
                        background: var(--nc-bg);
                    "
                >
                    <div v-if="!recoveryCodesList.length" class="space-y-2">
                        <div
                            v-for="n in 8"
                            :key="n"
                            class="nc-pulse h-4"
                            style="
                                border-radius: var(--nc-radius-sm);
                                background: var(--nc-neutral-900);
                            "
                        ></div>
                    </div>
                    <div
                        v-else
                        v-for="(code, index) in recoveryCodesList"
                        :key="index"
                    >
                        {{ code }}
                    </div>
                </div>
                <p class="nc-t-xs nc-tone-muted select-none">
                    {{
                        $t(
                            'Each recovery code can be used once to access your account and will be removed after use. If you need more, click',
                        )
                    }}
                    <span class="font-bold">{{ $t('Regenerate codes') }}</span>
                    {{ $t('above.') }}
                </p>
            </div>
        </div>
    </SectionCard>
</template>
