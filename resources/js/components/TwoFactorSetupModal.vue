<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { PhCheck, PhCopy, PhScan } from '@phosphor-icons/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { invalidField } from '@/lib/fieldErrors';
import { confirm } from '@/routes/two-factor';
import type { TwoFactorConfigContent } from '@/types';

type Props = {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
};

const props = defineProps<Props>();
const isOpen = defineModel<boolean>('isOpen');

const { copy, copied } = useClipboard();
const { qrCodeSvg, manualSetupKey, clearSetupData, fetchSetupData, errors } =
    useTwoFactorAuth();

const showVerificationStep = ref(false);
const code = ref<string>('');

const pinInputContainerRef = useTemplateRef('pinInputContainerRef');

const modalConfig = computed<TwoFactorConfigContent>(() => {
    if (props.twoFactorEnabled) {
        return {
            title: 'Two-factor authentication enabled',
            description:
                'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
            buttonText: 'Close',
        };
    }

    if (showVerificationStep.value) {
        return {
            title: 'Verify authentication code',
            description: 'Enter the 6-digit code from your authenticator app',
            buttonText: 'Continue',
        };
    }

    return {
        title: 'Enable two-factor authentication',
        description:
            'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
        buttonText: 'Continue',
    };
});

const handleModalNextStep = () => {
    if (props.requiresConfirmation) {
        showVerificationStep.value = true;

        nextTick(() => {
            pinInputContainerRef.value?.querySelector('input')?.focus();
        });

        return;
    }

    clearSetupData();
    isOpen.value = false;
};

const resetModalState = () => {
    if (props.twoFactorEnabled) {
        clearSetupData();
    }

    showVerificationStep.value = false;
    code.value = '';
};

watch(
    () => isOpen.value,
    async (isOpen) => {
        if (!isOpen) {
            resetModalState();

            return;
        }

        if (!qrCodeSvg.value) {
            await fetchSetupData();
        }
    },
);
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader class="flex items-center justify-center">
                <span
                    class="mb-3 grid size-11 place-items-center rounded-full"
                    style="
                        background: var(--nc-surface);
                        box-shadow: var(--nc-shadow-sm);
                    "
                >
                    <PhScan :size="22" />
                </span>
                <DialogTitle>{{ $t(modalConfig.title) }}</DialogTitle>
                <DialogDescription class="text-center">
                    {{ $t(modalConfig.description) }}
                </DialogDescription>
            </DialogHeader>

            <div
                class="relative flex w-auto flex-col items-center justify-center space-y-5"
            >
                <template v-if="!showVerificationStep">
                    <AlertError v-if="errors?.length" :errors="errors" />
                    <template v-else>
                        <div
                            class="relative mx-auto aspect-square w-64 overflow-hidden"
                            style="
                                border: 1px solid var(--nc-divider);
                                border-radius: var(--nc-radius-md);
                            "
                        >
                            <div
                                v-if="!qrCodeSvg"
                                class="absolute inset-0 grid place-items-center"
                            >
                                <Spinner class="size-6" />
                            </div>
                            <div
                                v-else
                                v-html="qrCodeSvg"
                                class="flex aspect-square size-full items-center justify-center p-5"
                                style="filter: invert(1) brightness(1.5)"
                            />
                        </div>

                        <button
                            type="button"
                            class="nc-btn nc-btn-primary w-full"
                            @click="handleModalNextStep"
                        >
                            {{ $t(modalConfig.buttonText) }}
                        </button>

                        <div
                            class="nc-t-xs nc-tone-muted flex w-full items-center gap-3"
                        >
                            <span
                                class="h-px flex-1"
                                style="background: var(--nc-divider)"
                            />
                            {{ $t('or, enter the code manually') }}
                            <span
                                class="h-px flex-1"
                                style="background: var(--nc-divider)"
                            />
                        </div>

                        <div
                            v-if="!manualSetupKey"
                            class="grid w-full place-items-center"
                        >
                            <Spinner />
                        </div>
                        <div v-else class="relative w-full">
                            <input
                                type="text"
                                readonly
                                class="nc-input nc-code"
                                style="padding-right: 44px"
                                :value="manualSetupKey"
                                :aria-label="$t('Setup key')"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-[var(--nc-neutral-500)] hover:text-[var(--nc-text)]"
                                style="
                                    border-radius: 0 var(--nc-radius-md)
                                        var(--nc-radius-md) 0;
                                "
                                :aria-label="
                                    copied ? $t('Copied') : $t('Copy setup key')
                                "
                                @click="copy(manualSetupKey || '')"
                            >
                                <PhCheck
                                    v-if="copied"
                                    :size="16"
                                    style="color: var(--st-ok)"
                                />
                                <PhCopy v-else :size="16" />
                            </button>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <Form
                        v-bind="confirm.form()"
                        error-bag="confirmTwoFactorAuthentication"
                        reset-on-error
                        @finish="code = ''"
                        @success="isOpen = false"
                        v-slot="{ errors, processing }"
                    >
                        <input type="hidden" name="code" :value="code" />
                        <div
                            ref="pinInputContainerRef"
                            class="relative w-full space-y-3"
                        >
                            <div
                                class="flex w-full flex-col items-center justify-center space-y-3 py-2"
                            >
                                <InputOTP
                                    id="otp"
                                    v-model="code"
                                    :maxlength="6"
                                    :disabled="processing"
                                    autofocus
                                    autocomplete="one-time-code"
                                    :aria-label="$t('Authentication code')"
                                    v-bind="invalidField('otp', errors?.code)"
                                >
                                    <InputOTPGroup>
                                        <InputOTPSlot
                                            v-for="index in 6"
                                            :key="index"
                                            :index="index - 1"
                                        />
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError
                                    field="otp"
                                    :message="errors?.code"
                                />
                            </div>

                            <div class="flex w-full items-center gap-3">
                                <button
                                    type="button"
                                    class="nc-btn nc-btn-secondary flex-1"
                                    :disabled="processing"
                                    @click="showVerificationStep = false"
                                >
                                    {{ $t('Back') }}
                                </button>
                                <button
                                    type="submit"
                                    class="nc-btn nc-btn-primary flex-1"
                                    :disabled="processing || code.length < 6"
                                >
                                    {{ $t('Confirm') }}
                                </button>
                            </div>
                        </div>
                    </Form>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
