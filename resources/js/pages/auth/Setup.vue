<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/setup';

defineOptions({
    layout: {
        title: 'Set up Horizon Watch',
        description: 'Create the administrator account and the first organization',
    },
});
</script>

<template>
    <Head :title="$t('Set up Horizon Watch')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-2">
            <Label for="name">{{ $t('Full name') }}</Label>
            <Input id="name" name="name" required autofocus autocomplete="name" />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="email">{{ $t('Email address') }}</Label>
            <Input id="email" type="email" name="email" required autocomplete="email" placeholder="email@example.com" />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <Label for="password">{{ $t('Password') }}</Label>
            <PasswordInput id="password" name="password" required autocomplete="new-password" :placeholder="$t('at least 10 characters')" />
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">{{ $t('Confirm password') }}</Label>
            <PasswordInput id="password_confirmation" name="password_confirmation" required autocomplete="new-password" />
        </div>

        <div class="grid gap-2">
            <Label for="organization">{{ $t('Organization name') }}</Label>
            <Input id="organization" name="organization" required />
            <InputError :message="errors.organization" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing" data-test="setup-button">
            <Spinner v-if="processing" />
            {{ $t('Create account') }}
        </Button>
    </Form>
</template>
