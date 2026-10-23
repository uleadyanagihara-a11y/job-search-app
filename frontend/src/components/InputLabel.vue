<script setup lang="ts">
// 入力欄との関連付けは呼び出し側で `for` を渡す（attrs として label に付く）。
defineProps<{ value?: string }>();
</script>

<template>
    <label class="block text-sm font-medium text-gray-700">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
