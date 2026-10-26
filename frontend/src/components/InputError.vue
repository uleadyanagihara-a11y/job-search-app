<script setup lang="ts">
// useApiForm の errors[field]（422 の field ごとの最初の message）を表示する。
// 入力欄から `aria-describedby` で参照できるよう、`id` は呼び出し側で渡す（attrs として div に付く）。
defineProps<{ message?: string }>();
</script>

<template>
    <div v-show="message">
        <p class="text-sm text-red-600">
            {{ message }}
        </p>
    </div>
</template>
