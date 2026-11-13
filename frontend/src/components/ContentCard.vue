<script setup lang="ts">
// 画面の中の 1 区画。title を渡すと見出し（h2）と説明を付け、section の accessible name にする。
import { useId } from 'vue';

defineProps<{ title?: string; description?: string }>();

const headingId = useId();
</script>

<template>
    <section
        class="bg-surface p-4 shadow-sm sm:rounded-lg sm:p-8"
        :aria-labelledby="title ? headingId : undefined"
    >
        <header
            v-if="title"
            class="mb-6"
        >
            <h2
                :id="headingId"
                class="text-lg font-medium text-fg-strong"
            >
                {{ title }}
            </h2>

            <p
                v-if="description"
                class="mt-1 text-sm text-fg-muted"
            >
                {{ description }}
            </p>
        </header>

        <slot />
    </section>
</template>
