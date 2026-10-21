<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LocaleSwitch from '@/components/LocaleSwitch.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import AlertEmailsToggle from '@/components/settings/AlertEmailsToggle.vue';
import { invalidField } from '@/lib/fieldErrors';

defineOptions({
    layout: { title: 'Profile settings' },
});

defineProps<{
    alertEmails: boolean;
    accountDeletion: App.Data.Settings.AccountDeletionData;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const email = ref(user.value.email);
const changesEmail = computed(
    () => email.value.trim().toLowerCase() !== user.value.email,
);
</script>

<template>
    <Head :title="$t('Profile settings')" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Profile')"
            :description="$t('Update your name and email address')"
        />

        <Form
            v-bind="ProfileController.update.form()"
            :reset-on-success="['current_password']"
            :reset-on-error="['current_password']"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="nc-field">
                <label for="name">{{ $t('Name') }}</label>
                <input
                    id="name"
                    class="nc-input"
                    name="name"
                    :value="user.name"
                    required
                    autocomplete="name"
                    v-bind="invalidField('name', errors.name)"
                />
                <InputError field="name" :message="errors.name" />
            </div>

            <div class="nc-field">
                <label for="email">{{ $t('Email') }}</label>
                <input
                    id="email"
                    v-model="email"
                    class="nc-input"
                    type="email"
                    name="email"
                    required
                    autocomplete="username"
                    placeholder="email@example.com"
                    v-bind="invalidField('email', errors.email)"
                />
                <InputError field="email" :message="errors.email" />
            </div>

            <div v-if="changesEmail" class="nc-field">
                <label for="current_password">{{
                    $t('Current password')
                }}</label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    required
                    autocomplete="current-password"
                    :placeholder="$t('Current password')"
                    v-bind="
                        invalidField(
                            'current_password',
                            errors.current_password,
                        )
                    "
                />
                <p class="nc-t-xs nc-tone-muted mt-1">
                    {{
                        $t(
                            'Changing the email address needs your current password. The old address gets a notice of the change.',
                        )
                    }}
                </p>
                <InputError
                    field="current_password"
                    :message="errors.current_password"
                />
            </div>

            <button
                type="submit"
                class="nc-btn nc-btn-primary"
                :disabled="processing"
                data-test="update-profile-button"
            >
                {{ $t('Save') }}
            </button>
        </Form>
    </div>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Language')"
            :description="
                $t(
                    'The language of the panel for your account, on every device.',
                )
            "
        />
        <LocaleSwitch
            name="settings-locale"
            padding="6px 14px"
            font-size="13px"
            long
            class="self-start"
        />
    </div>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Alert emails')"
            :description="
                $t(
                    'Receive the alert emails of the environments you can see, in every organization you belong to.',
                )
            "
        />
        <AlertEmailsToggle :enabled="alertEmails" class="self-start">
            {{ $t('Send me alert emails') }}
        </AlertEmailsToggle>
    </div>

    <DeleteUser :deletion="accountDeletion" />
</template>
