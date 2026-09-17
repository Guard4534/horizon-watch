<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhBellRinging,
    PhCaretRight,
    PhEnvelopeSimple,
    PhMoon,
    PhShieldCheck,
    PhSignOut,
    PhTranslate,
    PhUsersThree,
} from '@phosphor-icons/vue';
import { computed, onMounted, watch } from 'vue';
import LocaleSwitch from '@/components/LocaleSwitch.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import { useInitials } from '@/composables/useInitials';
import { useIsMobile } from '@/composables/useIsMobile';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { logout } from '@/routes';
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
const { getInitials } = useInitials();

const isMobile = useIsMobile();

function leaveIfWide(): void {
    if (!isMobile.value) {
        router.visit(profileEdit(), { replace: true });
    }
}

onMounted(leaveIfWide);
watch(isMobile, leaveIfWide);
</script>

<template>
    <Head :title="$t('Profile')" />

    <div class="flex flex-col" style="padding-bottom: var(--nc-space-4)">
        <div
            class="flex items-center gap-[10px]"
            style="padding: var(--nc-space-4)"
        >
            <span class="avatar">{{ getInitials(user.name) }}</span>
            <div class="min-w-0">
                <div class="truncate" style="font-size: 14px">
                    {{ user.name }}
                </div>
                <div
                    class="truncate"
                    style="font-size: 11px; color: var(--nc-neutral-500)"
                >
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
            <button
                type="button"
                class="row"
                disabled
                :title="$t('Available soon')"
            >
                <PhBellRinging :size="16" class="icon" />
                <span class="label">{{ $t('Push notifications') }}</span>
                <span class="value">{{ $t('Available soon') }}</span>
            </button>
            <button
                type="button"
                class="row"
                disabled
                :title="$t('Available soon')"
            >
                <PhEnvelopeSimple :size="16" class="icon" />
                <span class="label">{{ $t('Alert emails') }}</span>
                <span class="value">{{ $t('Available soon') }}</span>
            </button>
            <button
                type="button"
                class="row"
                disabled
                :title="$t('Available soon')"
            >
                <PhMoon :size="16" class="icon" />
                <span class="label">{{ $t('Quiet hours') }}</span>
                <span class="value">{{ $t('Available soon') }}</span>
            </button>
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

.row:disabled {
    opacity: 0.45;
    cursor: not-allowed;
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
