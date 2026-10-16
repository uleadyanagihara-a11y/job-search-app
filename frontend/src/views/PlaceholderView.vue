<script setup lang="ts">
import { useRoute } from 'vue-router';

// Phase 4 で各画面を移植するまでの仮 view。route の解決と guard の確認用。
const route = useRoute();
</script>

<template>
    <section>
        <h1>{{ route.meta.title }}</h1>
        <p>この画面は未移植です（route: {{ String(route.name) }}）。</p>
    </section>
</template>
