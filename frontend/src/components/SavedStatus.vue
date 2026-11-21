<script setup lang="ts">
// 保存の成功を少しの間だけ表示する（useApiForm の recentlySuccessful を渡す）。
// live region（role="status"）は常に描画し、中の文言だけを切り替える。live region を文言ごと差し込むと、
// 多くのスクリーンリーダーは「既にある live region の中身の変化」ではないため読み上げない。
defineProps<{ show: boolean }>();
</script>

<template>
    <p
        role="status"
        class="text-sm text-fg-muted"
    >
        <Transition
            enter-active-class="transition ease-in-out"
            enter-from-class="opacity-0"
            leave-active-class="transition ease-in-out"
            leave-to-class="opacity-0"
        >
            <span v-if="show"><slot>保存しました。</slot></span>
        </Transition>
    </p>
</template>
