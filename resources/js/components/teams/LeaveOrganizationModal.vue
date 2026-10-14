<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
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

            <p class="nc-t-sm">{{ organization?.name }}</p>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <button type="button" class="nc-btn nc-btn-secondary">
                        {{ $t('Cancel') }}
                    </button>
                </DialogClose>

                <button
                    type="button"
                    class="nc-btn nc-btn-danger"
                    data-test="leave-team-confirm"
                    :disabled="processing"
                    @click="confirm"
                >
                    {{ $t('Leave the organization') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
