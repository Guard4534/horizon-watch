<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
import OtherSessionsController from '@/actions/App/Http/Controllers/Settings/OtherSessionsController';
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

const open = ref(false);
const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Other sessions')"
            :description="
                $t(
                    'Log out of the panel on every other browser and device. Changing the password does this too.',
                )
            "
        />
        <Dialog v-model:open="open">
            <DialogTrigger as-child>
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    data-test="logout-other-sessions-button"
                >
                    {{ $t('Log out other sessions') }}
                </button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="OtherSessionsController.destroy.form()"
                    reset-on-success
                    @success="open = false"
                    @error="() => passwordInput?.focus()"
                    :options="{
                        preserveScroll: true,
                    }"
                    class="space-y-6"
                    v-slot="{ errors, processing, reset, clearErrors }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>{{
                            $t('Log out other sessions')
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                $t(
                                    'Enter your password to log out of every other browser and device. This one stays logged in.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-1">
                        <label for="other-sessions-password" class="sr-only">{{
                            $t('Password')
                        }}</label>
                        <PasswordInput
                            id="other-sessions-password"
                            name="password"
                            ref="passwordInput"
                            autocomplete="current-password"
                            :placeholder="$t('Password')"
                            v-bind="
                                invalidField(
                                    'other-sessions-password',
                                    errors.password,
                                )
                            "
                        />
                        <InputError
                            field="other-sessions-password"
                            :message="errors.password"
                        />
                    </div>

                    <DialogFooter>
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
                            class="nc-btn nc-btn-primary"
                            :disabled="processing"
                            data-test="confirm-logout-other-sessions-button"
                        >
                            {{ $t('Log out other sessions') }}
                        </button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
