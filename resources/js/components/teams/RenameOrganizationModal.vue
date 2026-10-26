<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { invalidField } from '@/lib/fieldErrors';
import { update } from '@/routes/teams';

const organization = defineModel<App.Data.Teams.UserTeamData | null>(
    'organization',
    { required: true },
);
</script>

<template>
    <Dialog
        :open="organization !== null"
        @update:open="(open) => !open && (organization = null)"
    >
        <DialogContent v-if="organization">
            <Form
                :key="organization.slug"
                v-bind="update.form(organization.slug)"
                class="space-y-6"
                v-slot="{ errors, processing, isDirty }"
                @success="organization = null"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Rename the organization')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'The name changes for everyone. Links and addresses keep working.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="nc-field">
                    <label for="rename-organization">{{
                        $t('Organization name')
                    }}</label>
                    <input
                        id="rename-organization"
                        class="nc-input"
                        name="name"
                        data-test="rename-team-name"
                        :value="organization.name"
                        required
                        v-bind="
                            invalidField('rename-organization', errors.name)
                        "
                    />
                    <InputError
                        field="rename-organization"
                        :message="errors.name"
                    />
                </div>

                <DialogFooter>
                    <DialogClose as-child>
                        <button type="button" class="nc-btn nc-btn-secondary">
                            {{ $t('Cancel') }}
                        </button>
                    </DialogClose>

                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        data-test="rename-team-submit"
                        :disabled="processing || !isDirty"
                    >
                        {{ $t('Save') }}
                    </button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
