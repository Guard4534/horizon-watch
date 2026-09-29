<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { leave } from '@/routes/teams';

const organization = defineModel<App.Data.Teams.UserTeamData | null>(
    'organization',
    { required: true },
);

const processing = ref(false);

function confirm(): void {
    const team = organization.value;

    if (!team) {
        return;
    }

    router.visit(leave(team.slug), {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => (organization.value = null),
    });
}
</script>

<template>
    <Dialog
        :open="organization !== null"
        @update:open="(open) => !open && (organization = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('Leave the organization') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'You lose access to every environment of this organization. Your account and your other organizations are untouched, and you can be invited again.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <p class="font-medium">{{ organization?.name }}</p>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>

                <Button
                    data-test="leave-team-confirm"
                    variant="destructive"
                    :disabled="processing"
                    @click="confirm"
                >
                    {{ $t('Leave the organization') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
