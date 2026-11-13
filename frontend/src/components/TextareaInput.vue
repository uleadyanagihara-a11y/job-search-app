<script setup lang="ts">
// 複数行のプレーンテキスト。表示側は改行だけを反映する（docs/domain-design.md 3 章「本文の書式」）。
const model = defineModel<string>({ required: true });
</script>

<template>
    <textarea
        v-model="model"
        class="rounded-md border-line-strong shadow-xs focus:border-primary-500 focus:ring-primary-500"
    />
</template>
