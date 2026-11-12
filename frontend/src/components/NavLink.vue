<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';

// active は呼び出し側が route 名で判定して渡す（旧 `route().current()` の置き換え）。
const props = defineProps<{ to: RouteLocationRaw; active?: boolean }>();

const classes = computed(() =>
    props.active
        ? 'inline-flex items-center px-1 pt-1 border-b-2 border-primary-400 text-sm font-medium leading-5 text-fg-strong focus:outline-hidden focus:border-primary-700 transition duration-150 ease-in-out'
        : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-fg-subtle hover:text-fg-secondary hover:border-line-strong focus:outline-hidden focus:text-fg-secondary focus:border-line-strong transition duration-150 ease-in-out',
);
</script>

<template>
    <RouterLink
        :to="to"
        :class="classes"
        :aria-current="active ? 'page' : undefined"
    >
        <slot />
    </RouterLink>
</template>
