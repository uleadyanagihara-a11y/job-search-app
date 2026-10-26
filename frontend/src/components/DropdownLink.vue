<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';

// `to` があれば画面遷移の RouterLink、無ければ button（logout 等の API 呼び出し用。§3.3 で link にしない）。
defineProps<{ to?: RouteLocationRaw }>();

const classes =
    'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none';
</script>

<template>
    <RouterLink
        v-if="to !== undefined"
        :to="to"
        :class="classes"
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
