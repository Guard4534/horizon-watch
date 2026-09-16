<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhDotsThree } from '@phosphor-icons/vue';
import { ref } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    ASSIGNABLE_ROLES,
    roleTagClass,
    VISIBILITIES,
    visibilityLabel,
} from '@/lib/members';
import {
    destroy as destroyMember,
    update as updateMember,
} from '@/routes/members';

const { members, environments, canManage } = defineProps<{
    members: App.Data.Teams.MemberData[];
    environments: App.Data.Pages.EnvironmentOptionData[];
    canManage: boolean;
}>();

const slug = useTeamSlug();

const processing = ref(false);
// Role changes are refused by the server in one case the interface cannot
// predict on its own (the last admin besides the owner demoting themselves),
// so the message it sends back is shown rather than swallowed.
const error = ref<string | null>(null);

const manualFor = ref<App.Data.Teams.MemberData | null>(null);
const manualIds = ref<number[]>([]);
const manualError = ref<string | null>(null);

const removing = ref<App.Data.Teams.MemberData | null>(null);

// The owner's row has no menu: TeamPolicy::updateMember and removeMember
// both refuse when the target is the owner, so offering the actions would
// only produce a 403.
const isActionable = (member: App.Data.Teams.MemberData) =>
    canManage && !member.isOwner;

function patch(
    member: App.Data.Teams.MemberData,
    data: Record<string, string | number | number[]>,
    report: (message: string | null) => void,
    onSuccess?: () => void,
) {
    router.visit(updateMember([slug.value, member.id]), {
        data,
        preserveScroll: true,
        onStart: () => {
            processing.value = true;
            report(null);
        },
        onFinish: () => (processing.value = false),
        onError: (errors) =>
            report(
                errors.role ??
                    errors.visibility ??
                    errors.environmentIds ??
                    null,
            ),
        onSuccess: () => onSuccess?.(),
    });
}

const changeRole = (
    member: App.Data.Teams.MemberData,
    role: App.Enums.TeamRole,
) => patch(member, { role }, (message) => (error.value = message));

function changeVisibility(
    member: App.Data.Teams.MemberData,
    visibility: App.Enums.MemberVisibility,
) {
    if (visibility === 'manual') {
        manualFor.value = member;
        manualIds.value = [];
        manualError.value = null;

        return;
    }

    patch(member, { visibility }, (message) => (error.value = message));
}

function saveManual() {
    const member = manualFor.value;

    if (!member) {
        return;
    }

    patch(
        member,
        { visibility: 'manual', environmentIds: manualIds.value },
        (message) => (manualError.value = message),
        () => (manualFor.value = null),
    );
}

function confirmRemove() {
    const member = removing.value;

    if (!member) {
        return;
    }

    router.visit(destroyMember([slug.value, member.id]), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            removing.value = null;
        },
    });
}
</script>

<template>
    <SectionCard :title="`${$t('Members')} · ${members.length}`">
        <div
            v-if="error"
            class="mb-[var(--nc-space-3)]"
            style="
                border-radius: var(--nc-radius-md);
                border: 1px solid var(--st-down);
                padding: 8px 10px;
                font-size: 12px;
                color: var(--st-down);
            "
            role="alert"
        >
            {{ error }}
        </div>

        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Person') }}</th>
                        <th>{{ $t('Role') }}</th>
                        <th>{{ $t('Visible environments') }}</th>
                        <th>{{ $t('Last seen') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="member in members" :key="member.id">
                        <td>
                            <div class="flex items-center gap-[9px]">
                                <span
                                    class="grid place-items-center"
                                    style="
                                        width: 26px;
                                        height: 26px;
                                        flex: none;
                                        border-radius: 50%;
                                        background: var(--nc-neutral-800);
                                        color: var(--nc-neutral-200);
                                        font-size: 10px;
                                    "
                                    >{{ member.initials }}</span
                                >
                                <div>
                                    <div
                                        class="flex items-center gap-2"
                                        style="font-size: 13px"
                                    >
                                        {{ member.name }}
                                        <span
                                            v-if="member.isSelf"
                                            class="nc-tag nc-tag-sm nc-tag-neutral"
                                            >{{ $t('you') }}</span
                                        >
                                    </div>
                                    <div
                                        style="
                                            font-size: 11px;
                                            color: var(--nc-neutral-500);
                                        "
                                    >
                                        {{ member.email }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span :class="roleTagClass(member.role)">{{
                                member.roleLabel
                            }}</span>
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                        >
                            {{ member.visibilityLabel }}
                            <div
                                v-if="member.visibleEnvironmentNames.length"
                                style="
                                    font-size: 11px;
                                    color: var(--nc-neutral-600);
                                "
                            >
                                {{ member.visibleEnvironmentNames.join(' · ') }}
                            </div>
                        </td>
                        <td
                            class="whitespace-nowrap"
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-600);
                            "
                        >
                            <!-- No "last seen" is recorded anywhere yet: see
                                 MemberController::index. -->
                            —
                        </td>
                        <td class="text-right">
                            <DropdownMenu v-if="isActionable(member)">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="nc-btn nc-btn-ghost"
                                        style="
                                            font-size: 12px;
                                            color: var(--nc-neutral-400);
                                        "
                                        :aria-label="
                                            $t('Actions for :name', {
                                                name: member.name,
                                            })
                                        "
                                        :disabled="processing"
                                    >
                                        <PhDotsThree :size="16" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuLabel>{{
                                        $t('Role')
                                    }}</DropdownMenuLabel>
                                    <DropdownMenuItem
                                        v-for="role in ASSIGNABLE_ROLES"
                                        :key="role"
                                        :disabled="member.role === role"
                                        @click="changeRole(member, role)"
                                    >
                                        {{ role }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuLabel>{{
                                        $t('Visible environments')
                                    }}</DropdownMenuLabel>
                                    <DropdownMenuItem
                                        v-for="visibility in VISIBILITIES"
                                        :key="visibility"
                                        :disabled="
                                            member.visibility === visibility &&
                                            visibility !== 'manual'
                                        "
                                        @click="
                                            changeVisibility(member, visibility)
                                        "
                                    >
                                        {{ visibilityLabel(visibility)
                                        }}{{
                                            visibility === 'manual' ? '…' : ''
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        style="color: var(--st-down)"
                                        @click="removing = member"
                                    >
                                        {{ $t('Remove from the organization') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>

    <Dialog
        :open="manualFor !== null"
        @update:open="(open) => !open && (manualFor = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    $t('Pick the visible environments')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Only the environments you tick here will be visible to this person.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex max-h-[45vh] flex-col overflow-y-auto"
                style="gap: var(--nc-space-2)"
            >
                <label
                    v-for="environment in environments"
                    :key="environment.id"
                    class="flex items-center gap-2"
                    style="font-size: 13px"
                >
                    <input
                        v-model="manualIds"
                        type="checkbox"
                        :value="environment.id"
                    />
                    {{ environment.name }}
                </label>
                <div
                    v-if="!environments.length"
                    style="font-size: 12px; color: var(--nc-neutral-500)"
                >
                    {{
                        $t(
                            'This organization has no environment yet, so there is nothing to pick.',
                        )
                    }}
                </div>
            </div>

            <p
                v-if="manualError"
                style="font-size: 12px; color: var(--st-down)"
                role="alert"
            >
                {{ manualError }}
            </p>

            <DialogFooter class="gap-2">
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    @click="manualFor = null"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    :disabled="processing"
                    @click="saveManual"
                >
                    {{ $t('Save') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="removing !== null"
        @update:open="(open) => !open && (removing = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    $t('Remove from the organization')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'They lose access to every environment of this organization. Their account and their other organizations are untouched, and they can be invited again.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <p style="font-size: 13px">{{ removing?.name }}</p>

            <DialogFooter class="gap-2">
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    @click="removing = null"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    style="background: var(--st-down)"
                    :disabled="processing"
                    @click="confirmRemove"
                >
                    {{ $t('Remove') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
