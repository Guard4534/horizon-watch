<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { PhDotsThree } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
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

// One form for both writes, the phase's single submit idiom: it carries the
// in-flight flag and the validation messages, so neither is rebuilt by hand
// here. transform() is set on every submit (the same shape the application
// forms use) because the removal has no payload of its own and must not
// carry the update's fields in its DELETE body.
const form = useForm<App.Data.Teams.UpdateMemberData>({
    role: null,
    visibility: null,
    environmentIds: [],
});

// Every message the server sent back, not a chosen one: a rejected
// environment keys as "environmentIds.0" rather than "environmentIds", so
// naming the fields by hand left the dialog showing nothing and looking
// stuck. Deduplicated, because two rejected ids carry the same sentence.
// A 403, a 404 or a throttle's 429 is not a validation response and is
// handled by the exception handler, not here.
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

const losingTheLastAdmin = computed(() =>
    losesTheLastAdmin(removing.value, removing.value?.isSelf === true, members),
);

// The owner's row has no menu: TeamPolicy::updateMember and removeMember
// both refuse when the target is the owner, so offering the actions would
// only produce a 403.
const isActionable = (member: App.Data.Teams.MemberData) =>
    canManage && !member.isOwner;

function patch(
    member: App.Data.Teams.MemberData,
    fields: Partial<App.Data.Teams.UpdateMemberData>,
    onSuccess?: () => void,
) {
    // Everything not being changed goes back to its empty value:
    // UpdateMemberData wants exactly one of role and visibility, and asks
    // for environmentIds only with "manual".
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
        // Seeded from what the member already has: the payload replaces the
        // whole list, so opening on an empty set would revoke every grant
        // the admin did not re-tick.
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

function confirmRemove() {
    const member = removing.value;

    if (!member) {
        return;
    }

    // Closed either way, with any reason left in the banner above the table
    // rather than inside a dialog that is gone. onError is unreachable
    // today: nothing in MemberController::destroy raises a
    // ValidationException. It stays as the landing place for the first rule
    // that refuses a removal.
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
        <!-- Hidden while the manual-visibility dialog is open: that dialog
             shows the same messages next to the checkboxes they belong
             to. -->
        <div
            v-if="errors.length && manualFor === null"
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
            <p v-for="message in errors" :key="message">{{ message }}</p>
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
                            <!-- Always the em dash today: nothing records a
                                 last access yet, see MemberController::index. -->
                            {{ member.lastSeenAt ?? '—' }}
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
                v-for="message in errors"
                :key="message"
                style="font-size: 12px; color: var(--st-down)"
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

            <p style="font-size: 13px">{{ removing?.name }}</p>

            <p
                v-if="losingTheLastAdmin"
                style="font-size: 12px; color: var(--st-warn)"
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
                    class="nc-btn nc-btn-primary"
                    style="background: var(--st-down)"
                    :disabled="form.processing"
                    @click="confirmRemove"
                >
                    {{ $t('Remove') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
