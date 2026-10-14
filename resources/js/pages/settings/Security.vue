<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import LogoutOtherSessions from '@/components/settings/LogoutOtherSessions.vue';
import { invalidField } from '@/lib/fieldErrors';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: { title: 'Security settings' },
});
</script>

<template>
    <Head :title="$t('Security settings')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Update password')"
            :description="
                $t(
                    'Ensure your account is using a long, random password to stay secure',
                )
            "
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="nc-field">
                <label for="current_password">{{
                    $t('Current password')
                }}</label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    autocomplete="current-password"
                    :placeholder="$t('Current password')"
                    v-bind="
                        invalidField(
                            'current_password',
                            errors.current_password,
                        )
                    "
                />
                <InputError
                    field="current_password"
                    :message="errors.current_password"
                />
            </div>

            <div class="nc-field">
                <label for="password">{{ $t('New password') }}</label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    :placeholder="$t('New password')"
                    :passwordrules="props.passwordRules"
                    v-bind="invalidField('password', errors.password)"
                />
                <InputError field="password" :message="errors.password" />
            </div>

            <div class="nc-field">
                <label for="password_confirmation">{{
                    $t('Confirm password')
                }}</label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    :placeholder="$t('Confirm password')"
                    :passwordrules="props.passwordRules"
                    v-bind="
                        invalidField(
                            'password_confirmation',
                            errors.password_confirmation,
                        )
                    "
                />
                <InputError
                    field="password_confirmation"
                    :message="errors.password_confirmation"
                />
            </div>

            <button
                type="submit"
                class="nc-btn nc-btn-primary"
                :disabled="processing"
                data-test="update-password-button"
            >
                {{ $t('Save') }}
            </button>
        </Form>
    </div>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />

    <LogoutOtherSessions />
</template>
