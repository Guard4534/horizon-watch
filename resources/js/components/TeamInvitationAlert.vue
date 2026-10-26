<script setup lang="ts">
import { PhCheckCircle, PhInfo, PhWarningCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';

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
    <div
        data-test="team-invitation-alert"
        :data-state="state"
        role="alert"
        class="nc-t-sm flex gap-3"
        :style="{
            padding: 'var(--nc-space-3) var(--nc-space-4)',
            border: `1px solid ${tone}`,
            borderRadius: 'var(--nc-radius-md)',
            background: `color-mix(in srgb, ${tone} 10%, transparent)`,
        }"
    >
        <component
            :is="icon"
            :size="16"
            class="mt-0.5 flex-none"
            :style="{ color: tone }"
        />
        <p>
            <template v-if="state === 'sign_in_required'">
                {{
                    $t(
                        'This address already has an account. Log in to accept the invitation.',
                    )
                }}
            </template>
            <template v-else-if="state === 'wrong_account'">
                {{
                    $t(
                        'This invitation was sent to another address. Log out, then open the link again.',
                    )
                }}
            </template>
            <template v-else-if="state === 'expired'">
                {{
                    $t(
                        'This invitation has expired. Ask an admin to send a new one.',
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
        </p>
    </div>
</template>
