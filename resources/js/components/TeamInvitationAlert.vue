<script setup lang="ts">
import { PhCheckCircle, PhInfo, PhWarningCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';

// The banner of an invitation that cannot be acted on as it stands. It says
// what happened and nothing more: InvitationPageData sends no organization
// name and no email for these states, so there is nothing else it could say.
const props = defineProps<{
    state: string;
}>();

// The message arms below stay literal $t() calls, one per state, so
// TranslationsTest can see them; only the tone and the icon are computed.
const tone = computed(() => {
    switch (props.state) {
        case 'accepted':
            return 'var(--st-ok)';
        case 'sign_in_required':
            return 'var(--nc-accent)';
        default:
            return 'var(--st-warn)';
    }
});

const icon = computed(() => {
    switch (props.state) {
        case 'accepted':
            return PhCheckCircle;
        case 'sign_in_required':
            return PhInfo;
        default:
            return PhWarningCircle;
    }
});
</script>

<template>
    <div data-test="team-invitation-alert" :data-state="state">
        <Alert
            :style="{
                borderColor: tone,
                background: `color-mix(in srgb, ${tone} 10%, transparent)`,
            }"
        >
            <component :is="icon" class="size-4" :style="{ color: tone }" />
            <AlertDescription style="color: var(--nc-text)">
                <template v-if="state === 'sign_in_required'">
                    {{
                        $t(
                            'This address already has an account. Sign in to accept the invitation.',
                        )
                    }}
                </template>
                <template v-else-if="state === 'wrong_account'">
                    {{
                        $t(
                            'This invitation was sent to another address. Sign out, then open the link again.',
                        )
                    }}
                </template>
                <template v-else-if="state === 'expired'">
                    {{
                        $t(
                            'This invitation has expired. Ask an administrator to send a new one.',
                        )
                    }}
                </template>
                <template v-else-if="state === 'revoked'">
                    {{ $t('This invitation is no longer valid.') }}
                </template>
                <template v-else-if="state === 'accepted'">
                    {{ $t('This invitation has already been accepted.') }}
                </template>
                <!-- A state this build does not know about: say the one
                     thing that is true of all of them rather than draw an
                     empty box. -->
                <template v-else>
                    {{ $t('This invitation cannot be used.') }}
                </template>
            </AlertDescription>
        </Alert>
    </div>
</template>
