<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';

// `to` が無ければ button（logout 等の API 呼び出し用）。active の判定は NavLink と同じく呼び出し側で行う。
const props = defineProps<{ to?: RouteLocationRaw; active?: boolean }>();

const classes = computed(() =>
    props.active
        ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-primary-400 text-start text-base font-medium text-primary-700 bg-primary-50 focus:outline-hidden focus:text-primary-800 focus:bg-primary-100 focus:border-primary-700 transition duration-150 ease-in-out'
        : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-fg-muted hover:text-fg hover:bg-surface-subtle hover:border-line-strong focus:outline-hidden focus:text-fg focus:bg-surface-subtle focus:border-line-strong transition duration-150 ease-in-out',
);
</script>

<template>
    <RouterLink
        v-if="to !== undefined"
        :to="to"
        :class="classes"
        :aria-current="active ? 'page' : undefined"
    >
        <slot />
    </RouterLink>
    <button
        v-else
        type="button"
        :class="classes"
    >
        <slot />
    </button>
</template>
