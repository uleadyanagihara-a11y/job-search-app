<script setup lang="ts">
// 確認 Modal を開くボタンとしても使うため、form 内で意図せず submit しないよう既定は button にする。
withDefaults(defineProps<{ type?: 'button' | 'submit' | 'reset' }>(), { type: 'button' });
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center whitespace-nowrap rounded-md border border-transparent bg-danger-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-fg-on-emphasis transition duration-150 ease-in-out hover:bg-danger-500 focus:outline-hidden focus:ring-2 focus:ring-danger-500 focus:ring-offset-2 active:bg-danger-700 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
