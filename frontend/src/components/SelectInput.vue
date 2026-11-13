<script setup lang="ts">
// option は slot で渡す。値は文字列で受け、数値への変換は呼び出し側で行う。
const model = defineModel<string>({ required: true });
</script>

<template>
    <select
        v-model="model"
        class="rounded-md border-line-strong shadow-xs focus:border-primary-500 focus:ring-primary-500"
    >
        <slot />
    </select>
</template>
