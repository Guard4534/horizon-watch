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
import { login, logout } from '@/routes';
import { accept, decline, register } from '@/routes/invitations';

defineOptions({
    layout: {
        kicker: 'Invitation',
        title: 'You have been invited',
    },
});

const props = defineProps<{
    page: App.Data.Pages.InvitationPageData;
}>();

// Only an open invitation carries an organization, a role, a visibility and
// an email: for every other state InvitationPageData holds nulls on purpose,
// so those states render their message and one way out.
const isOpen = computed(() => props.page.state === 'open');

// The three states nobody can do anything about. sign_in_required and
// wrong_account are excluded on purpose: each has its own action below, and
// GET /login is guest-gated, so the link would bounce the signed-in visitor
// of wrong_account straight back into the panel.
const showLoginFooter = computed(() =>
    ['expired', 'revoked', 'accepted'].includes(props.page.state),
);

// Drops the cached pages of the account being left behind, like the user
// menu's own sign-out does.
const flushOnSignOut = () => router.flushAll();
</script>

<template>
    <Head :title="$t('You have been invited')" />

    <template v-if="isOpen">
        <p
            style="
                font-size: 13px;
                color: var(--nc-neutral-400);
                margin: 0 0 var(--nc-space-4);
            "
        >
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
                <label for="email">{{ $t('Email address') }}</label>
                <!-- The address is the one the invitation was sent to: shown
                     so nobody registers the wrong account, never submitted. -->
                <input
                    id="email"
                    class="nc-input"
                    type="email"
                    :value="page.email"
                    disabled
                />
            </div>

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
                style="
                    font-size: 11px;
                    color: var(--nc-neutral-600);
                    margin: var(--nc-space-3) 0 0;
                "
            >
                {{
                    $t('Invitation sent to :email', { email: page.email ?? '' })
                }}
            </p>

            <div
                class="flex gap-[var(--nc-space-2)]"
                style="margin-top: var(--nc-space-4)"
            >
                <Form
                    v-bind="accept.form(page.code)"
                    v-slot="{ processing }"
                    class="flex-1"
                    data-test="invitation-accept-form"
                >
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary nc-btn-block"
                        :disabled="processing"
                        data-test="invitation-accept-button"
                    >
                        <Spinner v-if="processing" />
                        {{ $t('Accept invitation') }}
                    </button>
                </Form>

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
            </div>
        </template>
    </template>

    <template v-else>
        <TeamInvitationAlert :state="page.state" />

        <!-- The address already has an account: signing in is the only way
             forward, so it is the primary action rather than a footnote. -->
        <Link
            v-if="page.state === 'sign_in_required'"
            :href="login()"
            class="nc-btn nc-btn-primary nc-btn-block"
            style="margin-top: var(--nc-space-4)"
            data-test="invitation-sign-in-button"
        >
            {{ $t('Sign in') }}
        </Link>

        <!-- Signed in as somebody else: POST to logout, because GET /login
             is guest-gated and would only bounce back into the panel. -->
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
            {{ $t('Sign out and open the link again') }}
        </Link>
    </template>

    <div
        v-if="showLoginFooter"
        class="text-center"
        style="
            font-size: 12px;
            color: var(--nc-neutral-500);
            margin-top: var(--nc-space-4);
        "
    >
        <span>{{ $t('Or, return to') }}&nbsp;</span>
        <TextLink :href="login()">{{ $t('log in') }}</TextLink>
    </div>
</template>
