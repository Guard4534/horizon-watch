<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        kicker: 'Sign in',
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
                :tabindex="1"
                autocomplete="email"
                placeholder="email@example.com"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="nc-field">
            <div class="flex items-center justify-between">
                <label for="password">{{ $t('Password') }}</label>
                <TextLink
                    v-if="canResetPassword"
                    :href="request()"
                    class="text-sm"
                    :tabindex="5"
                >
                    {{ $t('Forgot your password?') }}
                </TextLink>
            </div>
            <PasswordInput
                id="password"
                class="nc-input"
                name="password"
                required
                :tabindex="2"
                autocomplete="current-password"
            />
            <InputError :message="errors.password" />
        </div>

        <label class="nc-radio nc-t-xs">
            <input type="checkbox" name="remember" :tabindex="3" />
            <span class="nc-dot" />
            {{ $t('Remember me') }}
        </label>

        <button
            type="submit"
            class="nc-btn nc-btn-primary nc-btn-block"
            :tabindex="4"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            {{ $t('Sign in') }}
        </button>
    </Form>
</template>
