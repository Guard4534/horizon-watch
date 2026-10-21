<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { PhPaperPlaneTilt, PhShieldCheck } from '@phosphor-icons/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { Spinner } from '@/components/ui/spinner';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { isProductionType } from '@/lib/environments';
import { invalidField } from '@/lib/fieldErrors';
import { ASSIGNABLE_ROLES, VISIBILITIES, visibilityLabel } from '@/lib/members';
import { store as storeInvitation } from '@/routes/members/invitations';

const { environments, expiresDays } = defineProps<{
    environments: App.Data.Pages.EnvironmentOptionData[];
    expiresDays: number;
}>();

const slug = useTeamSlug();

const role = ref<App.Enums.TeamRole>('member');
const visibility = ref<App.Enums.MemberVisibility>('all');
const environmentIds = ref<number[]>([]);
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
                <label for="invite-email">{{ $t('Email') }}</label>
                <input
                    id="invite-email"
                    class="nc-input"
                    type="email"
                    name="email"
                    required
                    placeholder="name@example.com"
                    v-bind="invalidField('invite-email', errors.email)"
                />
                <InputError field="invite-email" :message="errors.email" />
            </div>

            <div class="nc-field">
                <label id="invite-role">{{ $t('Role') }}</label>
                <div
                    class="flex flex-col"
                    style="gap: var(--nc-space-2); margin-top: 5px"
                    role="radiogroup"
                    aria-labelledby="invite-role"
                    v-bind="invalidField('invite-role', errors.role)"
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
                <InputError field="invite-role" :message="errors.role" />
            </div>

            <div v-if="role === 'admin'" class="nc-field">
                <input type="hidden" name="visibility" value="all" />
                <span class="nc-t-2xs nc-tone-muted">{{
                    $t('Admins and the owner always see every environment.')
                }}</span>
            </div>

            <div v-else class="nc-field">
                <label for="invite-visibility">{{
                    $t('Visible environments')
                }}</label>
                <select
                    id="invite-visibility"
                    v-model="visibility"
                    class="nc-input"
                    name="visibility"
                    v-bind="
                        invalidField('invite-visibility', errors.visibility)
                    "
                >
                    <option
                        v-for="option in VISIBILITIES"
                        :key="option"
                        :value="option"
                    >
                        {{ visibilityLabel(option) }}
                    </option>
                </select>
                <InputError
                    field="invite-visibility"
                    :message="errors.visibility"
                />
                <div
                    v-if="visibility === 'non_production' && !errors.visibility"
                    class="nc-t-2xs nc-tone-faint mt-1"
                >
                    {{ $t('Hides every environment of type Production.') }}
                </div>
            </div>

            <div
                v-if="role !== 'admin' && visibility === 'manual'"
                class="nc-field"
            >
                <label id="invite-environments">{{ $t('Environments') }}</label>
                <div
                    class="flex max-h-[220px] flex-col overflow-y-auto"
                    style="gap: 6px; margin-top: 5px"
                    role="group"
                    aria-labelledby="invite-environments"
                    v-bind="
                        invalidField(
                            'invite-environments',
                            errors.environmentIds,
                        )
                    "
                >
                    <label
                        v-for="environment in environments"
                        :key="environment.id"
                        class="nc-t-sm flex items-center gap-2"
                    >
                        <input
                            v-model="environmentIds"
                            type="checkbox"
                            name="environmentIds[]"
                            :value="environment.id"
                        />
                        {{ environment.name }}
                        <span
                            v-if="isProductionType(environment.type)"
                            class="nc-tone-muted inline-flex"
                            role="img"
                            :title="$t('Production environment')"
                            :aria-label="$t('Production environment')"
                        >
                            <PhShieldCheck :size="14" weight="bold" />
                        </span>
                    </label>
                    <div
                        v-if="!environments.length"
                        class="nc-t-xs nc-tone-muted"
                    >
                        {{
                            $t(
                                'This organization has no environment yet, so there is nothing to pick.',
                            )
                        }}
                    </div>
                </div>
                <InputError
                    field="invite-environments"
                    :message="errors.environmentIds"
                />
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

            <div class="nc-t-2xs nc-tone-faint">
                {{
                    $t(
                        'The invitation expires after :days days and can be resent.',
                        { days: String(expiresDays) },
                    )
                }}
            </div>
        </Form>
    </SectionCard>
</template>
