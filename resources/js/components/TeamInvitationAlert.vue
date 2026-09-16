<script setup lang="ts">
import { PhInfo } from '@phosphor-icons/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { TeamInvitationContext } from '@/types';

defineProps<{
    invitation: TeamInvitationContext;
}>();
</script>

<template>
    <div data-test="team-invitation-alert">
        <Alert
            style="
                border-color: var(--nc-accent);
                background: color-mix(
                    in srgb,
                    var(--nc-accent) 10%,
                    transparent
                );
            "
        >
            <PhInfo class="size-4" style="color: var(--nc-accent)" />
            <AlertDescription style="color: var(--nc-text)">
                {{
                    $t('Log in to join the :team team.', {
                        team: invitation.teamName,
                    })
                }}
            </AlertDescription>
        </Alert>
    </div>
</template>
