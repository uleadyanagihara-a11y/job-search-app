<script setup lang="ts">
// form 全体の失敗（422 以外の ApiError、form の field に無い key の 422、読み込みの失敗など）を伝える。field の error は InputError を使う。
</script>

<template>
    <p
        role="alert"
        class="text-sm text-danger-600"
    >
        <slot />
    </p>
</template>
