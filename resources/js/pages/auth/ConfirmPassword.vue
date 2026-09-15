<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        kicker: 'Security',
        title: 'Confirm your password',
        description: 'This is a secure area of the application. Please confirm your password before continuing.',
    },
});
</script>

<template>
    <Head :title="$t('Confirm your password')" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
        class="flex flex-col gap-[var(--nc-space-3)]"
    >
        <div class="nc-field">
            <label for="password">{{ $t('Password') }}</label>
            <PasswordInput
                id="password"
                class="nc-input"
                name="password"
                required
                autocomplete="current-password"
                autofocus
            />
            <InputError :message="errors.password" />
        </div>

        <button type="submit" class="nc-btn nc-btn-primary nc-btn-block" :disabled="processing" data-test="confirm-password-button">
            <Spinner v-if="processing" />
            {{ $t('Confirm password') }}
        </button>
    </Form>
</template>
