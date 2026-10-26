<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import { invalidField } from '@/lib/fieldErrors';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Welcome back',
        description: '',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head :title="$t('Log in')" />

    <div
        v-if="status"
        class="nc-t-xs mb-4 text-center"
        style="color: var(--st-ok)"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
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
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
                v-bind="invalidField('email', errors.email)"
            />
            <InputError field="email" :message="errors.email" />
        </div>

        <div class="nc-field">
            <label for="password">{{ $t('Password') }}</label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="current-password"
                v-bind="invalidField('password', errors.password)"
            />
            <InputError field="password" :message="errors.password" />
        </div>

        <label class="nc-checkbox nc-t-xs">
            <input type="checkbox" name="remember" />
            <span class="nc-box" />
            {{ $t('Remember me') }}
        </label>

        <button
            type="submit"
            class="nc-btn nc-btn-primary nc-btn-block"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            {{ $t('Log in') }}
        </button>

        <TextLink
            v-if="canResetPassword"
            :href="request()"
            class="self-center text-sm"
        >
            {{ $t('Forgot your password?') }}
        </TextLink>
    </Form>
</template>
