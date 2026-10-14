<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
import OtherSessionsController from '@/actions/App/Http/Controllers/Settings/OtherSessionsController';
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
                <Button
                    variant="secondary"
                    data-test="logout-other-sessions-button"
                    >{{ $t('Log out other sessions') }}</Button
                >
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
                                    'Enter your password to log out of every other browser and device. This one stays signed in.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="other-sessions-password" class="sr-only">{{
                            $t('Password')
                        }}</Label>
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
                            :disabled="processing"
                            data-test="confirm-logout-other-sessions-button"
                        >
                            {{ $t('Log out other sessions') }}
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
