<script setup lang="ts">
withDefaults(defineProps<{ type?: 'button' | 'submit' | 'reset' }>(), { type: 'button' });
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-line-strong bg-surface px-4 py-2 text-xs font-semibold uppercase tracking-widest text-fg-secondary shadow-xs transition duration-150 ease-in-out hover:bg-surface-subtle focus:outline-hidden focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
