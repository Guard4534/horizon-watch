<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { store } from '@/routes/teams';

const id = useId();
const open = ref(false);
const formKey = ref(0);

function handleOpenChange(value: boolean) {
    open.value = value;

    if (!value) {
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader>
                    <DialogTitle>{{ $t('New organization') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'Applications, environments, members and alert rules all live inside one organization.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="nc-field">
                    <label :for="id">{{ $t('Organization name') }}</label>
                    <input
                        :id="id"
                        class="nc-input"
                        name="name"
                        data-test="create-team-name"
                        :placeholder="$t('My organization')"
                        required
                        v-bind="invalidField(id, errors.name)"
                    />
                    <InputError :field="id" :message="errors.name" />
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
                        data-test="create-team-submit"
                        :disabled="processing"
                    >
                        {{ $t('Create the organization') }}
                    </button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
