<script setup lang="ts">
import { onMounted, useTemplateRef } from 'vue';

const model = defineModel<string>({ required: true });

const input = useTemplateRef<HTMLInputElement>('input');

// 画面遷移後の SPA では HTML の autofocus が効かないことがあるため、mount 時に明示的に focus する。
onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        ref="input"
        v-model="model"
        class="rounded-md border-line-strong shadow-xs focus:border-primary-500 focus:ring-primary-500"
    >
</template>
