<script setup lang="ts">
// form の送信ボタンとして使うことが多いため、既定の type は submit にする。
withDefaults(defineProps<{ type?: 'button' | 'submit' | 'reset' }>(), { type: 'submit' });
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
