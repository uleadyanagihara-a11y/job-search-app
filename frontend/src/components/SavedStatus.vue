<script setup lang="ts">
// 保存の成功を少しの間だけ表示する（useApiForm の recentlySuccessful を渡す）。
defineProps<{ show: boolean }>();
</script>

<template>
    <Transition
        enter-active-class="transition ease-in-out"
        enter-from-class="opacity-0"
        leave-active-class="transition ease-in-out"
        leave-to-class="opacity-0"
    >
        <p
            v-if="show"
            role="status"
            class="text-sm text-fg-muted"
        >
            <slot>保存しました。</slot>
        </p>
    </Transition>
</template>
