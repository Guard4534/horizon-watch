<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/setup';

defineOptions({
    layout: {
        kicker: 'First run',
        title: 'Set up Horizon Watch',
        description:
            'Create the administrator account and the first organization',
    },
});
</script>

<template>
    <Head :title="$t('Set up Horizon Watch')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-[var(--nc-space-3)]"
    >
        <div class="nc-field">
            <label for="name">{{ $t('Full name') }}</label>
            <input
                id="name"
                class="nc-input"
                name="name"
                required
                autofocus
                autocomplete="name"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="nc-field">
            <label for="email">{{ $t('Email address') }}</label>
            <input
                id="email"
                class="nc-input"
                type="email"
                name="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="nc-field">
            <label for="password">{{ $t('Password') }}</label>
            <PasswordInput
                id="password"
                class="nc-input"
                name="password"
                required
                autocomplete="new-password"
                :placeholder="$t('at least 10 characters')"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="nc-field">
            <label for="password_confirmation">{{
                $t('Confirm password')
            }}</label>
            <PasswordInput
                id="password_confirmation"
                class="nc-input"
                name="password_confirmation"
                required
                autocomplete="new-password"
            />
        </div>

        <div class="nc-field">
            <label for="organization">{{ $t('Organization name') }}</label>
            <input
                id="organization"
                class="nc-input"
                name="organization"
                required
            />
            <InputError :message="errors.organization" />
        </div>

        <button
            type="submit"
            class="nc-btn nc-btn-primary nc-btn-block"
            :disabled="processing"
            data-test="setup-button"
        >
            <Spinner v-if="processing" />
            {{ $t('Create account') }}
        </button>
    </Form>

    <p
        style="
            font-size: 11px;
            color: var(--nc-neutral-600);
            margin-top: var(--nc-space-4);
        "
    >
        {{ $t('Everyone else joins by invitation from inside the panel.') }}
    </p>
</template>
