<script setup lang="ts">
// Breeze の `v-model:checked` は使わず、通常の v-model（boolean）にする。
const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <input
        v-model="checked"
        type="checkbox"
        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
    >
</template>
