<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        kicker: 'Sign in',
        title: 'Forgot your password?',
        description: 'Enter your email to receive a password reset link',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="$t('Forgot your password?')" />

    <div
        v-if="status"
        class="nc-t-xs mb-4 text-center"
        style="color: var(--st-ok)"
    >
        {{ status }}
    </div>

    <Form
        v-bind="email.form()"
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
                autocomplete="off"
                autofocus
                placeholder="email@example.com"
            />
            <InputError :message="errors.email" />
        </div>

        <button
            type="submit"
            class="nc-btn nc-btn-primary nc-btn-block"
            :disabled="processing"
            data-test="email-password-reset-link-button"
        >
            <Spinner v-if="processing" />
            {{ $t('Email password reset link') }}
        </button>
    </Form>

    <div
        class="nc-t-xs nc-tone-muted text-center"
        style="margin-top: var(--nc-space-4)"
    >
        <span>{{ $t('Or, return to') }}</span>
        <TextLink :href="login()">{{ $t('log in') }}</TextLink>
    </div>
</template>
