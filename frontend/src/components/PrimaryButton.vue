<script setup lang="ts">
// form の送信ボタンとして使うことが多いため、既定の type は submit にする。
withDefaults(defineProps<{ type?: 'button' | 'submit' | 'reset' }>(), { type: 'submit' });
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center whitespace-nowrap rounded-md border border-transparent bg-emphasis px-4 py-2 text-xs font-semibold uppercase tracking-widest text-fg-on-emphasis transition duration-150 ease-in-out hover:bg-emphasis-hover focus:bg-emphasis-hover focus:outline-hidden focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 active:bg-emphasis-active disabled:opacity-25"
    >
        <slot />
    </button>
</template>
