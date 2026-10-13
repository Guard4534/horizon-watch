<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

const { deletion } = defineProps<{
    deletion: App.Data.Settings.AccountDeletionData;
}>();

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Delete account')"
            :description="$t('Delete your account for good')"
        />
        <div
            class="space-y-4 rounded-lg p-4"
            style="
                border: 1px solid var(--st-down);
                background: color-mix(in srgb, var(--st-down) 10%, transparent);
            "
        >
            <div class="nc-tone-down relative space-y-0.5">
                <p class="font-medium">{{ $t('Caution') }}</p>
                <p class="text-sm">
                    {{
                        $t(
                            'Please proceed with caution, this cannot be undone.',
                        )
                    }}
                </p>
            </div>
            <div
                v-if="deletion.sharedOrganizations.length"
                class="nc-tone-down space-y-2 text-sm"
                data-test="delete-user-blocked"
            >
                <p>
                    {{
                        $t(
                            'You own organizations that other people belong to. Transfer their ownership from the Members page, or delete them, before deleting your account.',
                        )
                    }}
                </p>
                <ul class="list-disc pl-5">
                    <li
                        v-for="(
                            organization, index
                        ) in deletion.sharedOrganizations"
                        :key="index"
                    >
                        {{ organization }}
                    </li>
                </ul>
            </div>
            <Dialog v-else>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        data-test="delete-user-button"
                        >{{ $t('Delete account') }}</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>{{
                                $t(
                                    'Are you sure you want to delete your account?',
                                )
                            }}</DialogTitle>
                            <DialogDescription>
                                {{
                                    $t(
                                        'Your account is deleted for good and you leave every organization you belong to. Enter your password to confirm.',
                                    )
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div
                            v-if="deletion.soleOrganizations.length"
                            class="space-y-2 text-sm"
                        >
                            <p>
                                {{
                                    $t(
                                        'Nobody else belongs to these organizations, so they are deleted too, with every application, environment, alert rule and pending invitation:',
                                    )
                                }}
                            </p>
                            <ul class="list-disc pl-5">
                                <li
                                    v-for="(
                                        organization, index
                                    ) in deletion.soleOrganizations"
                                    :key="index"
                                >
                                    {{ organization }}
                                </li>
                            </ul>
                        </div>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">{{
                                $t('Password')
                            }}</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                :placeholder="$t('Password')"
                            />
                            <InputError :message="errors.password" />
                            <InputError :message="errors.account" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{ $t('Cancel') }}
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                {{ $t('Delete account') }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
