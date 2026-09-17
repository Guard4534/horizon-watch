<script setup lang="ts">
import { PhCheckCircle, PhInfo, PhWarningCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';

const props = defineProps<{
    state: string;
}>();

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
                <template v-else>
                    {{ $t('This invitation cannot be used.') }}
                </template>
            </AlertDescription>
        </Alert>
    </div>
</template>
