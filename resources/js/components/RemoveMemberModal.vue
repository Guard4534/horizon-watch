<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy as destroyMember } from '@/routes/teams/members';
import type { Team, TeamMember } from '@/types';

type Props = {
    team: Team;
    member: TeamMember | null;
    open: boolean;
    // Whether the target is the person clicking, and whether that person is
    // the last admin besides the owner. Computed by the page, which is the
    // only place that has both the member list and the authenticated user.
    isSelf?: boolean;
    losingTheLastAdmin?: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

// No payload: the membership to drop is in the URL. useForm is the phase's
// one submit idiom and it owns the in-flight flag.
const form = useForm({});

const removeMember = () => {
    if (!props.member) {
        return;
    }

    form.delete(destroyMember([props.team.slug, props.member.id]).url, {
        onSuccess: () => emit('update:open', false),
    });
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('Remove team member') }}</DialogTitle>
                <DialogDescription v-if="props.isSelf">
                    {{ $t('You are about to remove yourself from this team.') }}
                </DialogDescription>
                <DialogDescription v-else>
                    {{ $t('Are you sure you want to remove') }}
                    <strong>{{ props.member?.name }}</strong>
                    {{ $t('from this team?') }}
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="props.losingTheLastAdmin"
                class="text-sm"
                style="color: var(--st-warn)"
            >
                {{
                    $t(
                        'You are the only admin besides the owner: after this, nobody but the owner will be able to invite, remove or change members.',
                    )
                }}
            </p>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> {{ $t('Cancel') }} </Button>
                </DialogClose>

                <Button
                    data-test="remove-member-confirm"
                    variant="destructive"
                    :disabled="form.processing"
                    @click="removeMember"
                >
                    {{ $t('Remove member') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
