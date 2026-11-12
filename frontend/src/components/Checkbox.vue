<script setup lang="ts">
// Breeze の `v-model:checked` は使わず、通常の v-model（boolean）にする。
const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <input
        v-model="checked"
        type="checkbox"
        class="rounded-sm border-line-strong text-primary-600 shadow-xs focus:ring-primary-500"
    >
</template>
