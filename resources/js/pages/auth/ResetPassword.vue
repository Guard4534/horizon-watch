<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        kicker: 'Sign in',
        title: 'Choose a new password',
        description: '',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head :title="$t('Choose a new password')" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-[var(--nc-space-3)]"
    >
        <div class="nc-field">
            <label for="email">{{ $t('Email') }}</label>
            <input
                id="email"
                class="nc-input"
                type="email"
                name="email"
                autocomplete="email"
                v-model="inputEmail"
                readonly
            />
            <InputError :message="errors.email" />
        </div>

        <div class="nc-field">
            <label for="password">{{ $t('Password') }}</label>
            <PasswordInput
                id="password"
                class="nc-input"
                name="password"
                autocomplete="new-password"
                autofocus
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="nc-field">
            <label for="password_confirmation">{{ $t('Confirm password') }}</label>
            <PasswordInput
                id="password_confirmation"
                class="nc-input"
                name="password_confirmation"
                autocomplete="new-password"
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password_confirmation" />
        </div>

        <button type="submit" class="nc-btn nc-btn-primary nc-btn-block" :disabled="processing" data-test="reset-password-button">
            <Spinner v-if="processing" />
            {{ $t('Reset password') }}
        </button>
    </Form>
</template>
