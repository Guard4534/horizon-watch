<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { PhSignOut } from '@phosphor-icons/vue';
import { computed } from 'vue';
import InvitationCard from '@/components/auth/InvitationCard.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import { invalidField } from '@/lib/fieldErrors';
import { login, logout } from '@/routes';
import { accept, decline, register } from '@/routes/invitations';

defineOptions({
    layout: {
        title: 'You have been invited',
    },
});

const props = defineProps<{
    page: App.Data.Pages.InvitationPageData;
}>();

const isOpen = computed(() => props.page.state === 'open');

const showLoginFooter = computed(() =>
    ['expired', 'revoked', 'accepted'].includes(props.page.state),
);

const flushOnSignOut = () => router.flushAll();
</script>

<template>
    <Head :title="$t('You have been invited')" />

    <template v-if="isOpen">
        <p class="nc-t-sm nc-tone-soft" style="margin: 0 0 var(--nc-space-4)">
            {{ $t('Complete your profile to join the organization.') }}
        </p>

        <InvitationCard
            :organization-name="page.organizationName ?? ''"
            :role-label="page.roleLabel ?? ''"
            :visibility-label="page.visibilityLabel ?? ''"
            :visible-environment-names="page.visibleEnvironmentNames"
        />

        <Form
            v-if="!page.authenticated"
            v-bind="register.form(page.code)"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-[var(--nc-space-3)]"
            style="margin-top: var(--nc-space-4)"
            data-test="invitation-register-form"
        >
            <div class="nc-field">
                <label for="email">{{ $t('Email') }}</label>
                <input
                    id="email"
                    class="nc-input"
                    type="email"
                    :value="page.email"
                    disabled
                />
            </div>

            <div class="nc-field">
                <label for="name">{{ $t('Name') }}</label>
                <input
                    id="name"
                    class="nc-input"
                    name="name"
                    required
                    autofocus
                    autocomplete="name"
                    v-bind="invalidField('name', errors.name)"
                />
                <InputError field="name" :message="errors.name" />
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

            <button
                type="submit"
                class="nc-btn nc-btn-primary nc-btn-block"
                :disabled="processing"
                data-test="invitation-join-button"
            >
                <Spinner v-if="processing" />
                {{ $t('Join the organization') }}
            </button>
        </Form>

        <template v-else>
            <p
                class="nc-t-2xs nc-tone-faint"
                style="margin: var(--nc-space-3) 0 0"
            >
                {{
                    $t('Invitation sent to :email', { email: page.email ?? '' })
                }}
            </p>

            <div class="nc-actions" style="margin-top: var(--nc-space-4)">
                <Form
                    v-bind="decline.form(page.code)"
                    v-slot="{ processing }"
                    data-test="invitation-decline-form"
                >
                    <button
                        type="submit"
                        class="nc-btn nc-btn-secondary"
                        :disabled="processing"
                        data-test="invitation-decline-button"
                    >
                        {{ $t('Decline') }}
                    </button>
                </Form>

                <Form
                    v-bind="accept.form(page.code)"
                    v-slot="{ processing }"
                    data-test="invitation-accept-form"
                >
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        :disabled="processing"
                        data-test="invitation-accept-button"
                    >
                        <Spinner v-if="processing" />
                        {{ $t('Accept invitation') }}
                    </button>
                </Form>
            </div>
        </template>
    </template>

    <template v-else>
        <TeamInvitationAlert :state="page.state" />

        <Link
            v-if="page.state === 'sign_in_required'"
            :href="login()"
            class="nc-btn nc-btn-primary nc-btn-block"
            style="margin-top: var(--nc-space-4)"
            data-test="invitation-sign-in-button"
        >
            {{ $t('Log in') }}
        </Link>

        <Link
            v-if="page.state === 'wrong_account'"
            :href="logout()"
            as="button"
            class="nc-btn nc-btn-primary nc-btn-block"
            style="margin-top: var(--nc-space-4)"
            @click="flushOnSignOut"
            data-test="invitation-sign-out-button"
        >
            <PhSignOut :size="14" />
            {{ $t('Log out and open the link again') }}
        </Link>
    </template>

    <div
        v-if="showLoginFooter"
        class="nc-t-xs nc-tone-muted text-center"
        style="margin-top: var(--nc-space-4)"
    >
        <span>{{ $t('Or, return to') }}&nbsp;</span>
        <TextLink :href="login()">{{ $t('log in') }}</TextLink>
    </div>
</template>
