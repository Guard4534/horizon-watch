<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

defineOptions({
    layout: {
        kicker: 'Sign in',
        title: 'Two-factor authentication',
    },
});

const showRecoveryInput = ref<boolean>(false);
const code = ref<string>('');

const authConfigContent = computed<Omit<TwoFactorConfigContent, 'title'>>(
    () => {
        if (showRecoveryInput.value) {
            return {
                description:
                    'Please confirm access to your account by entering one of your emergency recovery codes.',
                buttonText: 'login using an authentication code',
            };
        }

        return {
            description:
                'Enter the authentication code provided by your authenticator application.',
            buttonText: 'login using a recovery code',
        };
    },
);

watchEffect(() => {
    setLayoutProps({
        description: authConfigContent.value.description,
    });
});

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};
</script>

<template>
    <Head :title="$t('Two-factor authentication')" />

    <div class="flex flex-col gap-[var(--nc-space-4)]">
        <template v-if="!showRecoveryInput">
            <Form
                v-bind="store.form()"
                class="flex flex-col gap-[var(--nc-space-3)]"
                reset-on-error
                @error="code = ''"
                #default="{ errors, processing, clearErrors }"
            >
                <input type="hidden" name="code" :value="code" />
                <div
                    class="flex flex-col items-center justify-center gap-[var(--nc-space-3)] text-center"
                >
                    <div class="flex w-full items-center justify-center">
                        <InputOTP
                            id="otp"
                            v-model="code"
                            :maxlength="6"
                            :disabled="processing"
                            autofocus
                        >
                            <InputOTPGroup>
                                <InputOTPSlot
                                    v-for="index in 6"
                                    :key="index"
                                    :index="index - 1"
                                />
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                    <InputError :message="errors.code" />
                </div>
                <button
                    type="submit"
                    class="nc-btn nc-btn-primary nc-btn-block"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Continue') }}
                </button>
                <div class="nc-t-xs nc-tone-muted text-center">
                    <span>{{ $t('or you can') }} </span>
                    <button
                        type="button"
                        class="underline"
                        style="color: var(--nc-text)"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ $t(authConfigContent.buttonText) }}
                    </button>
                </div>
            </Form>
        </template>

        <template v-else>
            <Form
                v-bind="store.form()"
                class="flex flex-col gap-[var(--nc-space-3)]"
                reset-on-error
                #default="{ errors, processing, clearErrors }"
            >
                <div class="nc-field">
                    <label for="recovery_code">{{ $t('Recovery code') }}</label>
                    <input
                        id="recovery_code"
                        class="nc-input"
                        name="recovery_code"
                        type="text"
                        :placeholder="$t('Enter recovery code')"
                        :autofocus="showRecoveryInput"
                        required
                    />
                </div>
                <InputError :message="errors.recovery_code" />
                <button
                    type="submit"
                    class="nc-btn nc-btn-primary nc-btn-block"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Continue') }}
                </button>

                <div class="nc-t-xs nc-tone-muted text-center">
                    <span>{{ $t('or you can') }} </span>
                    <button
                        type="button"
                        class="underline"
                        style="color: var(--nc-text)"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ $t(authConfigContent.buttonText) }}
                    </button>
                </div>
            </Form>
        </template>
    </div>
</template>
