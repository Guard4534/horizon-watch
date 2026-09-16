<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { PhPaperPlaneTilt } from '@phosphor-icons/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { Spinner } from '@/components/ui/spinner';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { ASSIGNABLE_ROLES, VISIBILITIES, visibilityLabel } from '@/lib/members';
import { store as storeInvitation } from '@/routes/members/invitations';

const { environments } = defineProps<{
    environments: App.Data.Pages.EnvironmentOptionData[];
}>();

const slug = useTeamSlug();

// "member" rather than the mockup's "admin": the least surprising default
// for an invitation is the middle role, not the one that can reconfigure
// everything.
const role = ref<App.Enums.TeamRole>('member');
const visibility = ref<App.Enums.MemberVisibility>('all');
const environmentIds = ref<number[]>([]);
// Bumped on success to clear the uncontrolled email field.
const formKey = ref(0);

function reset() {
    role.value = 'member';
    visibility.value = 'all';
    environmentIds.value = [];
    formKey.value++;
}
</script>

<template>
    <SectionCard :title="$t('Invite to the organization')">
        <Form
            :key="formKey"
            v-bind="storeInvitation.form(slug)"
            class="flex flex-col"
            style="gap: var(--nc-space-3)"
            v-slot="{ errors, processing }"
            @success="reset"
        >
            <div class="nc-field">
                <label for="invite-email">Email</label>
                <input
                    id="invite-email"
                    class="nc-input"
                    type="email"
                    name="email"
                    required
                    placeholder="name@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="nc-field">
                <label>{{ $t('Role') }}</label>
                <div
                    class="flex flex-col"
                    style="gap: var(--nc-space-2); margin-top: 5px"
                >
                    <label
                        v-for="option in ASSIGNABLE_ROLES"
                        :key="option"
                        class="nc-radio"
                    >
                        <input
                            v-model="role"
                            type="radio"
                            name="role"
                            :value="option"
                        />
                        <span class="nc-dot"></span>{{ option }}
                    </label>
                </div>
                <InputError :message="errors.role" />
            </div>

            <div class="nc-field">
                <label for="invite-visibility">{{
                    $t('Visible environments')
                }}</label>
                <select
                    id="invite-visibility"
                    v-model="visibility"
                    class="nc-input"
                    name="visibility"
                >
                    <option
                        v-for="option in VISIBILITIES"
                        :key="option"
                        :value="option"
                    >
                        {{ visibilityLabel(option) }}
                    </option>
                </select>
                <InputError :message="errors.visibility" />
            </div>

            <div v-if="visibility === 'manual'" class="nc-field">
                <label>{{ $t('Environments') }}</label>
                <div
                    class="flex max-h-[220px] flex-col overflow-y-auto"
                    style="gap: 6px; margin-top: 5px"
                >
                    <label
                        v-for="environment in environments"
                        :key="environment.id"
                        class="flex items-center gap-2"
                        style="font-size: 13px"
                    >
                        <input
                            v-model="environmentIds"
                            type="checkbox"
                            name="environmentIds[]"
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
                <InputError :message="errors.environmentIds" />
            </div>

            <button
                type="submit"
                class="nc-btn nc-btn-primary nc-btn-block"
                :disabled="processing"
                data-test="invite-submit"
            >
                <Spinner v-if="processing" />
                <PhPaperPlaneTilt v-else :size="14" />
                {{ $t('Send invitation') }}
            </button>

            <div style="font-size: 11px; color: var(--nc-neutral-600)">
                {{
                    $t('The invitation expires after 7 days and can be resent.')
                }}
            </div>
        </Form>
    </SectionCard>
</template>
