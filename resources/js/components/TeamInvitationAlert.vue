<script setup lang="ts">
import { PhInfo } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { TeamInvitationContext } from '@/types';

type Props = {
    invitation: TeamInvitationContext;
    action: 'Log in' | 'Register';
};

const props = defineProps<Props>();

// Two full-sentence keys instead of a concatenated one: word order and
// agreement around ":team" differ once translated.
const message = computed(() =>
    props.action === 'Log in' ? 'Log in to join the :team team.' : 'Register to join the :team team.',
);
</script>

<template>
    <div data-test="team-invitation-alert">
        <Alert
            class="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400"
        >
            <PhInfo class="size-4" />
            <AlertDescription class="text-blue-900 dark:text-blue-100">
                {{ $t(message, { team: invitation.teamName }) }}
            </AlertDescription>
        </Alert>
    </div>
</template>
