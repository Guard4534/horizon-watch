<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { PhDotsThree, PhShieldCheck } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import Avatar from '@/components/nocturne/Avatar.vue';
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
    losingTheLastAdmin as losesTheLastAdmin,
    assignableRoleName,
    roleTagClass,
    VISIBILITIES,
    visibilityLabel,
} from '@/lib/members';
import {
    destroy as destroyMember,
    transferOwnership,
    update as updateMember,
} from '@/routes/members';

const { members, environments, canManage, canTransferOwnership } = defineProps<{
    members: App.Data.Teams.MemberData[];
    environments: App.Data.Pages.EnvironmentOptionData[];
    canManage: boolean;
    canTransferOwnership: boolean;
}>();

const slug = useTeamSlug();

const form = useForm<App.Data.Teams.UpdateMemberData>({
    role: null,
    visibility: null,
    environmentIds: [],
});

const errors = computed<string[]>(() => [
    ...new Set(
        Object.values(form.errors as Record<string, string | undefined>).filter(
            (message): message is string => typeof message === 'string',
        ),
    ),
]);

const manualFor = ref<App.Data.Teams.MemberData | null>(null);
const manualIds = ref<number[]>([]);

const removing = ref<App.Data.Teams.MemberData | null>(null);
const transferring = ref<App.Data.Teams.MemberData | null>(null);

const losingTheLastAdmin = computed(() =>
    losesTheLastAdmin(removing.value, removing.value?.isSelf === true, members),
);

const isActionable = (member: App.Data.Teams.MemberData) =>
    canManage && !member.isOwner;

function patch(
    member: App.Data.Teams.MemberData,
    fields: Partial<App.Data.Teams.UpdateMemberData>,
    onSuccess?: () => void,
) {
    form.role = fields.role ?? null;
    form.visibility = fields.visibility ?? null;
    form.environmentIds = fields.environmentIds ?? [];

    form.transform((data) => data).patch(
        updateMember([slug.value, member.id]).url,
        {
            preserveScroll: true,
            onSuccess: () => onSuccess?.(),
        },
    );
}

const changeRole = (
    member: App.Data.Teams.MemberData,
    role: App.Enums.TeamRole,
) => patch(member, { role });

function changeVisibility(
    member: App.Data.Teams.MemberData,
    visibility: App.Enums.MemberVisibility,
) {
    if (visibility === 'manual') {
        manualFor.value = member;
        manualIds.value = [...member.visibleEnvironmentIds];
        form.clearErrors();

        return;
    }

    patch(member, { visibility });
}

function saveManual() {
    const member = manualFor.value;

    if (!member) {
        return;
    }

    patch(
        member,
        { visibility: 'manual', environmentIds: manualIds.value },
        () => (manualFor.value = null),
    );
}

function confirmTransfer() {
    const member = transferring.value;

    if (!member) {
        return;
    }

    form.transform(() => ({})).post(
        transferOwnership([slug.value, member.id]).url,
        {
            preserveScroll: true,
            onSuccess: () => (transferring.value = null),
            onError: () => (transferring.value = null),
        },
    );
}

function confirmRemove() {
    const member = removing.value;

    if (!member) {
        return;
    }

    form.transform(() => ({})).delete(
        destroyMember([slug.value, member.id]).url,
        {
            preserveScroll: true,
            onSuccess: () => (removing.value = null),
            onError: () => (removing.value = null),
        },
    );
}
</script>

<template>
    <SectionCard :title="`${$t('Members')} · ${members.length}`">
        <div
            v-if="errors.length && manualFor === null"
            class="nc-t-xs nc-tone-down mb-[var(--nc-space-3)]"
            style="
                border-radius: var(--nc-radius-md);
                border: 1px solid var(--st-down);
                padding: 8px 10px;
            "
            role="alert"
        >
            <p v-for="message in errors" :key="message">{{ message }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Person') }}</th>
                        <th>{{ $t('Role') }}</th>
                        <th>{{ $t('Visible environments') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="member in members" :key="member.id">
                        <td>
                            <div class="flex items-center gap-[9px]">
                                <Avatar :name="member.name" />
                                <div>
                                    <div
                                        class="nc-t-sm flex items-center gap-2"
                                    >
                                        {{ member.name }}
                                        <span
                                            v-if="member.isSelf"
                                            class="nc-tag nc-tag-sm nc-tag-neutral"
                                            >{{ $t('you') }}</span
                                        >
                                    </div>
                                    <div class="nc-t-2xs nc-tone-muted">
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
                        <td class="nc-t-xs nc-tone-soft">
                            {{ member.visibilityLabel }}
                            <div
                                v-if="member.visibleEnvironmentNames.length"
                                class="nc-t-2xs nc-tone-faint"
                            >
                                {{ member.visibleEnvironmentNames.join(' · ') }}
                            </div>
                        </td>
                        <td class="nc-right">
                            <DropdownMenu v-if="isActionable(member)">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="nc-btn nc-btn-ghost nc-t-xs nc-tone-soft"
                                        :aria-label="
                                            $t('Actions for :name', {
                                                name: member.name,
                                            })
                                        "
                                        :disabled="form.processing"
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
                                        {{ assignableRoleName(role) }}
                                    </DropdownMenuItem>
                                    <template v-if="member.role !== 'admin'">
                                        <DropdownMenuSeparator />
                                        <DropdownMenuLabel>{{
                                            $t('Visible environments')
                                        }}</DropdownMenuLabel>
                                        <DropdownMenuItem
                                            v-for="visibility in VISIBILITIES"
                                            :key="visibility"
                                            :disabled="
                                                member.visibility ===
                                                    visibility &&
                                                visibility !== 'manual'
                                            "
                                            @click="
                                                changeVisibility(
                                                    member,
                                                    visibility,
                                                )
                                            "
                                        >
                                            <div class="flex flex-col">
                                                <span
                                                    >{{
                                                        visibilityLabel(
                                                            visibility,
                                                        )
                                                    }}{{
                                                        visibility === 'manual'
                                                            ? '…'
                                                            : ''
                                                    }}</span
                                                >
                                                <span
                                                    v-if="
                                                        visibility ===
                                                        'non_production'
                                                    "
                                                    class="nc-t-2xs nc-tone-faint max-w-[240px]"
                                                    >{{
                                                        $t(
                                                            'Hides every environment marked as production.',
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </DropdownMenuItem>
                                    </template>
                                    <template v-if="canTransferOwnership">
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            data-test="member-transfer-ownership"
                                            @click="transferring = member"
                                        >
                                            {{ $t('Transfer ownership') }}…
                                        </DropdownMenuItem>
                                    </template>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        class="nc-tone-down"
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
                            'Saving replaces the whole list: this person will see exactly the environments ticked here, and nothing else.',
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
                    class="nc-t-sm flex items-center gap-2"
                >
                    <input
                        v-model="manualIds"
                        type="checkbox"
                        :value="environment.id"
                    />
                    {{ environment.name }}
                    <span
                        v-if="environment.isProduction"
                        class="nc-tone-muted inline-flex"
                        role="img"
                        :title="$t('Production environment')"
                        :aria-label="$t('Production environment')"
                    >
                        <PhShieldCheck :size="14" weight="bold" />
                    </span>
                </label>
                <div v-if="!environments.length" class="nc-t-xs nc-tone-muted">
                    {{
                        $t(
                            'This organization has no environment yet, so there is nothing to pick.',
                        )
                    }}
                </div>
            </div>

            <p
                v-for="message in errors"
                :key="message"
                class="nc-t-xs nc-tone-down"
                role="alert"
            >
                {{ message }}
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
                    :disabled="form.processing"
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
                        removing?.isSelf
                            ? $t(
                                  'You lose access to every environment of this organization. Your account and your other organizations are untouched, and you can be invited again.',
                              )
                            : $t(
                                  'They lose access to every environment of this organization. Their account and their other organizations are untouched, and they can be invited again.',
                              )
                    }}
                </DialogDescription>
            </DialogHeader>

            <p class="nc-t-sm">{{ removing?.name }}</p>

            <p
                v-if="losingTheLastAdmin"
                class="nc-t-xs"
                style="color: var(--st-warn)"
            >
                {{
                    $t(
                        'You are the only admin besides the owner: after this, nobody but the owner will be able to invite, remove or change members.',
                    )
                }}
            </p>

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
                    class="nc-btn nc-btn-danger"
                    :disabled="form.processing"
                    @click="confirmRemove"
                >
                    {{ $t('Remove') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="transferring !== null"
        @update:open="(open) => !open && (transferring = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('Transfer ownership') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The new owner gets every permission, deleting the organization included. You stay on as an admin, and only the new owner can hand ownership back.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <p class="nc-t-sm">
                {{ transferring?.name }}
                <span class="nc-tone-muted">· {{ transferring?.email }}</span>
            </p>

            <DialogFooter class="gap-2">
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    @click="transferring = null"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    data-test="confirm-transfer-ownership"
                    :disabled="form.processing"
                    @click="confirmTransfer"
                >
                    {{ $t('Transfer ownership') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
