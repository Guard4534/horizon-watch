<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { PhShieldCheck } from '@phosphor-icons/vue';
import { onUnmounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <div v-if="canManageTwoFactor" class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Two-factor authentication')"
            :description="$t('Manage your two-factor authentication settings')"
        />

        <div
            v-if="!twoFactorEnabled"
            class="flex flex-col items-start justify-start space-y-4"
        >
            <p class="nc-t-sm nc-tone-muted">
                {{
                    $t(
                        'When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.',
                    )
                }}
            </p>

            <div>
                <button
                    v-if="hasSetupData"
                    type="button"
                    class="nc-btn nc-btn-primary"
                    @click="showSetupModal = true"
                >
                    <PhShieldCheck :size="16" />{{ $t('Continue setup') }}
                </button>
                <Form
                    v-else
                    v-bind="enable.form()"
                    @success="showSetupModal = true"
                    #default="{ processing }"
                >
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        :disabled="processing"
                    >
                        {{ $t('Enable 2FA') }}
                    </button>
                </Form>
            </div>
        </div>

        <div v-else class="flex flex-col items-start justify-start space-y-4">
            <p class="nc-t-sm nc-tone-muted">
                {{
                    $t(
                        'You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.',
                    )
                }}
            </p>

            <Form v-bind="disable.form()" #default="{ processing }">
                <button
                    type="submit"
                    class="nc-btn nc-btn-danger"
                    :disabled="processing"
                >
                    {{ $t('Disable 2FA') }}
                </button>
            </Form>

            <TwoFactorRecoveryCodes />
        </div>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </div>
</template>
