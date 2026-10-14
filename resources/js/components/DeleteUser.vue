<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
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
import { invalidField } from '@/lib/fieldErrors';

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
            class="space-y-4"
            style="
                padding: var(--nc-space-4);
                border-radius: var(--nc-radius-md);
                border: 1px solid var(--st-down);
                background: color-mix(in srgb, var(--st-down) 10%, transparent);
            "
        >
            <div class="nc-tone-down relative space-y-0.5">
                <p>{{ $t('Caution') }}</p>
                <p class="nc-t-sm">
                    {{
                        $t(
                            'Please proceed with caution, this cannot be undone.',
                        )
                    }}
                </p>
            </div>
            <div
                v-if="deletion.sharedOrganizations.length"
                class="nc-tone-down nc-t-sm space-y-2"
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
                    <button
                        type="button"
                        class="nc-btn nc-btn-danger"
                        data-test="delete-user-button"
                    >
                        {{ $t('Delete account') }}
                    </button>
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
                            class="nc-t-sm space-y-2"
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

                        <div class="space-y-1">
                            <label for="password" class="sr-only">{{
                                $t('Password')
                            }}</label>
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                :placeholder="$t('Password')"
                                v-bind="
                                    invalidField('password', errors.password)
                                "
                            />
                            <InputError
                                field="password"
                                :message="errors.password"
                            />
                            <InputError :message="errors.account" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <button
                                    type="button"
                                    class="nc-btn nc-btn-secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{ $t('Cancel') }}
                                </button>
                            </DialogClose>

                            <button
                                type="submit"
                                class="nc-btn nc-btn-danger"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                {{ $t('Delete account') }}
                            </button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
