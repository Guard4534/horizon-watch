<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button type="button" data-test="sidebar-menu-button" class="flex w-full items-center gap-2 text-left" style="padding: 0 var(--nc-space-2); background: transparent; border: 0; color: inherit; cursor: pointer">
                <span class="grid size-6 flex-none place-items-center rounded-full" style="background: var(--nc-neutral-800); color: var(--nc-neutral-200); font-size: 10px">
                    {{ getInitials(user.name) }}
                </span>
                <span class="min-w-0">
                    <span class="block truncate" style="font-size: 12px">{{ user.name }}</span>
                    <span class="block" style="font-size: 10px; color: var(--nc-neutral-600)">{{ page.props.currentTeam?.roleLabel }}</span>
                </span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="min-w-56" side="right" align="end" :side-offset="8">
            <UserMenuContent :user="user" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
