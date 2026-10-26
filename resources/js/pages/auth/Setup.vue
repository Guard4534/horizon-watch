<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Spinner } from '@/components/ui/spinner';
import { invalidField } from '@/lib/fieldErrors';
import { store } from '@/routes/setup';

defineOptions({
    layout: {
        title: 'Set up Horizon Watch',
        description:
            'Create the instance admin account and the first organization',
    },
});

defineProps<{
    page: App.Data.Pages.SetupPageData;
}>();
</script>

<template>
    <Head :title="$t('Set up Horizon Watch')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation', 'setupToken']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-[var(--nc-space-3)]"
    >
        <div v-if="page.requiresToken" class="nc-field">
            <label for="setupToken">{{ $t('Setup token') }}</label>
            <input
                id="setupToken"
                class="nc-input"
                type="password"
                name="setupToken"
                required
                autofocus
                autocomplete="off"
                spellcheck="false"
                v-bind="invalidField('setupToken', errors.setupToken)"
            />
            <div class="nc-t-2xs nc-tone-faint">
                {{
                    $t(
                        'The value of HORIZON_WATCH_SETUP_TOKEN in the configuration of the panel.',
                    )
                }}
            </div>
            <InputError field="setupToken" :message="errors.setupToken" />
        </div>

        <div class="nc-field">
            <label for="name">{{ $t('Name') }}</label>
            <input
                id="name"
                class="nc-input"
                name="name"
                required
                :autofocus="!page.requiresToken"
                autocomplete="name"
                v-bind="invalidField('name', errors.name)"
            />
            <InputError field="name" :message="errors.name" />
        </div>

        <div class="nc-field">
            <label for="email">{{ $t('Email') }}</label>
            <input
                id="email"
                class="nc-input"
                type="email"
                name="email"
                required
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
                autocomplete="new-password"
                :passwordrules="page.passwordPolicy.rules"
                v-bind="invalidField('password', errors.password)"
            />
            <div class="nc-t-2xs nc-tone-faint">
                {{ page.passwordPolicy.description }}
            </div>
            <InputError field="password" :message="errors.password" />
        </div>

        <div class="nc-field">
            <label for="password_confirmation">{{
                $t('Confirm password')
            }}</label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                :passwordrules="page.passwordPolicy.rules"
            />
        </div>

        <div class="nc-field">
            <label for="organization">{{ $t('Organization name') }}</label>
            <input
                id="organization"
                class="nc-input"
                name="organization"
                required
                v-bind="invalidField('organization', errors.organization)"
            />
            <InputError field="organization" :message="errors.organization" />
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

    <p class="nc-t-2xs nc-tone-faint" style="margin-top: var(--nc-space-4)">
        {{ $t('Everyone else joins by invitation from inside the panel.') }}
    </p>
</template>
