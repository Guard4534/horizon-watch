<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
                v-slot="{ errors, processing }"
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

                <div class="grid gap-2">
                    <Label for="rename-organization">{{
                        $t('Organization name')
                    }}</Label>
                    <Input
                        id="rename-organization"
                        name="name"
                        data-test="rename-team-name"
                        :default-value="organization.name"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{ $t('Cancel') }}</Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="rename-team-submit"
                        :disabled="processing"
                    >
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
