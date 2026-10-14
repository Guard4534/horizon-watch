<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Avatar from '@/components/nocturne/Avatar.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                data-test="sidebar-menu-button"
                class="flex w-full items-center gap-2 text-left"
                style="
                    padding: 0 var(--nc-space-2);
                    background: transparent;
                    border: 0;
                    color: inherit;
                    cursor: pointer;
                "
            >
                <Avatar :name="user.name" :size="24" />
                <span class="min-w-0">
                    <span class="nc-t-xs block truncate">{{ user.name }}</span>
                    <span class="nc-tone-faint block" style="font-size: 11px">{{
                        page.props.currentTeam?.roleLabel
                    }}</span>
                </span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="min-w-56"
            side="right"
            align="end"
            :side-offset="8"
        >
            <UserMenuContent :user="user" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
