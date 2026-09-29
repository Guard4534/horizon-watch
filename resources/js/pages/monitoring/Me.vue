<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhCaretRight,
    PhEnvelopeSimple,
    PhMoon,
    PhShieldCheck,
    PhSignOut,
    PhTranslate,
    PhUsersThree,
} from '@phosphor-icons/vue';
import { computed, watchEffect } from 'vue';
import LocaleSwitch from '@/components/LocaleSwitch.vue';
import AlertEmailsToggle from '@/components/settings/AlertEmailsToggle.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import { getInitials } from '@/lib/initials';
import { useIsMobile } from '@/composables/useIsMobile';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { logout } from '@/routes';
import { index as alertRulesIndex } from '@/routes/alert-rules';
import { index as membersIndex } from '@/routes/members';
import { edit as profileEdit } from '@/routes/profile';
import { edit as securityEdit } from '@/routes/security';

defineOptions({
    layout: { title: 'Profile' },
});

const { page } = defineProps<{
    page: App.Data.Pages.MePageData;
}>();

const shared = usePage();
const slug = useTeamSlug();
const user = computed(() => shared.props.auth.user);

const isMobile = useIsMobile();

watchEffect(
    () => {
        if (!isMobile.value) {
            router.visit(profileEdit(), { replace: true });
        }
    },
    { flush: 'post' },
);
</script>

<template>
    <Head :title="$t('Profile')" />

    <div
        v-if="isMobile"
        class="flex flex-col"
        style="padding-bottom: var(--nc-space-4)"
    >
        <div
            class="flex items-center gap-[10px]"
            style="padding: var(--nc-space-4)"
        >
            <span class="avatar">{{ getInitials(user.name) }}</span>
            <div class="min-w-0">
                <div class="truncate" style="font-size: 14px">
                    {{ user.name }}
                </div>
                <div class="nc-t-2xs nc-tone-muted truncate">
                    {{ user.email }}
                </div>
            </div>
        </div>

        <div style="padding: 0 var(--nc-space-4)">
            <TeamSwitcher />
        </div>

        <div
            class="flex flex-col"
            style="padding: var(--nc-space-3) var(--nc-space-4) 0"
        >
            <div class="row">
                <PhEnvelopeSimple :size="16" class="icon" />
                <span class="label-stack">
                    <span class="label">{{ $t('Alert emails') }}</span>
                    <span class="sub">{{
                        $t('for the environments you can see')
                    }}</span>
                </span>
                <AlertEmailsToggle
                    :enabled="page.alertEmails"
                    class="ml-auto"
                />
            </div>
            <Link :href="alertRulesIndex({ current_team: slug })" class="row">
                <PhMoon :size="16" class="icon" />
                <span class="label">{{ $t('Quiet hours') }}</span>
                <span class="value nc-num">{{
                    page.quietFrom && page.quietTo
                        ? `${page.quietFrom} → ${page.quietTo} (${page.timezone})`
                        : $t('None')
                }}</span>
                <PhCaretRight :size="13" class="caret" />
            </Link>
            <div class="row">
                <PhTranslate :size="16" class="icon" />
                <span class="label">{{ $t('Language') }}</span>
                <LocaleSwitch name="profile-locale" class="ml-auto" />
            </div>
            <Link :href="membersIndex(slug)" class="row">
                <PhUsersThree :size="16" class="icon" />
                <span class="label">{{ $t('Members') }}</span>
                <span class="value nc-num">{{ page.memberCount }}</span>
                <PhCaretRight :size="13" class="caret" />
            </Link>
            <Link :href="securityEdit()" class="row">
                <PhShieldCheck :size="16" class="icon" />
                <span class="label">{{ $t('Security') }}</span>
                <PhCaretRight :size="13" class="caret ml-auto" />
            </Link>
            <Link
                :href="logout()"
                as="button"
                class="row"
                data-test="logout-button"
                @click="router.flushAll()"
            >
                <PhSignOut :size="16" class="icon" />
                <span class="label">{{ $t('Log out') }}</span>
            </Link>
        </div>
    </div>
</template>

<style scoped>
.avatar {
    display: grid;
    place-items: center;
    flex: none;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    font-size: 13px;
    background: var(--nc-neutral-800);
    color: var(--nc-neutral-200);
}

.row {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: var(--nc-space-3) 0;
    border: 0;
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
}

div.row {
    cursor: default;
}

.label-stack {
    display: flex;
    min-width: 0;
    flex-direction: column;
}

.sub {
    font-size: 11px;
    color: var(--nc-neutral-500);
}

.icon {
    flex: none;
    color: var(--nc-neutral-400);
}

.label {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13px;
}

.value {
    flex: none;
    margin-left: auto;
    font-size: 11px;
    color: var(--nc-neutral-500);
}

.caret {
    flex: none;
    color: var(--nc-neutral-600);
}
</style>
