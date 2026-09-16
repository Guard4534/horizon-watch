<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import InvitationCard from '@/components/auth/InvitationCard.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { login } from '@/routes';
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

// The invitation code is not a page prop: it is the {code} segment of the
// URL this page is served at (/invitations/{code}), and copying it into the
// props would only give it a second place to be wrong.
const { currentUrl } = useCurrentUrl();
const code = computed(
    () => currentUrl.value.split('/').filter(Boolean)[1] ?? '',
);

// Only an open invitation carries an organization, a role, a visibility and
// an email: for every other state InvitationPageData holds nulls on purpose,
// so those states render their message and nothing else.
const isOpen = computed(() => props.page.state === 'open');
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
        />

        <Form
            v-if="!page.authenticated"
            v-bind="register.form(code)"
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
                    v-bind="accept.form(code)"
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
                    v-bind="decline.form(code)"
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
    </template>

    <!-- Every state but "open and already signed in" ends on the login
         page: the four closed states have nowhere else to go, and a guest
         who already has an account signs in and reopens the link. -->
    <div
        v-if="!isOpen || !page.authenticated"
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
