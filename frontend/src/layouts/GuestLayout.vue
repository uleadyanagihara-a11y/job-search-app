<script setup lang="ts">
import ApplicationLogo from '@/components/ApplicationLogo.vue';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0">
        <div>
            <RouterLink
                :to="{ name: 'home' }"
                aria-label="Job Search App"
            >
                <ApplicationLogo class="h-20 w-20 fill-current text-gray-500" />
            </RouterLink>
        </div>

        <div class="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg">
            <slot />
        </div>
    </div>
</template>
