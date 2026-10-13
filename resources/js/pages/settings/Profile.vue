<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LocaleSwitch from '@/components/LocaleSwitch.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AlertEmailsToggle from '@/components/settings/AlertEmailsToggle.vue';

defineOptions({
    layout: { title: 'Profile settings' },
});

defineProps<{
    alertEmails: boolean;
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

    <h1 class="sr-only">{{ $t('Profile settings') }}</h1>

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
            <div class="grid gap-2">
                <Label for="name">{{ $t('Name') }}</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="$t('Full name')"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    v-model="email"
                    required
                    autocomplete="username"
                    :placeholder="$t('Email address')"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="changesEmail" class="grid gap-2">
                <Label for="current_password">{{
                    $t('Current password')
                }}</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    :placeholder="$t('Current password')"
                />
                <p class="text-muted-foreground text-sm">
                    {{
                        $t(
                            'Changing the email address needs your current password. The old address gets a notice of the change.',
                        )
                    }}
                </p>
                <InputError class="mt-2" :message="errors.current_password" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    >{{ $t('Save') }}</Button
                >
            </div>
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

    <DeleteUser />
</template>
