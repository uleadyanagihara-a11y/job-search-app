<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import * as authApi from '@/api/auth';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { navigateAfterLogin } from '@/router/redirect';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const { data, errors, error, processing, submit, reset } = useApiForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

async function onSubmit(): Promise<void> {
    await submit(authApi.register, {
        onSuccess: async (result) => {
            auth.setUser(result.data);
            // redirect_to は dashboard。メール未認証なら guard が verification.notice へ送る。
            await navigateAfterLogin(router, result.meta.redirect_to, route.query.redirect);
        },
    });

    reset('password', 'password_confirmation');
}
</script>

<template>
    <GuestLayout>
        <p
            v-if="error"
            role="alert"
            class="mb-4 text-sm text-red-600"
        >
            {{ error.message }}
        </p>

        <form @submit.prevent="onSubmit">
            <div>
                <InputLabel
                    for="name"
                    value="Name"
                />

                <TextInput
                    id="name"
                    v-model="data.name"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="name"
                    :aria-invalid="errors.name ? 'true' : undefined"
                    :aria-describedby="errors.name ? 'name-error' : undefined"
                />

                <InputError
                    id="name-error"
                    class="mt-2"
                    :message="errors.name"
                />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="email"
                    value="Email"
                />

                <TextInput
                    id="email"
                    v-model="data.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autocomplete="username"
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                />

                <InputError
                    id="email-error"
                    class="mt-2"
                    :message="errors.email"
                />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password"
                    value="Password"
                />

                <TextInput
                    id="password"
                    v-model="data.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                    :aria-invalid="errors.password ? 'true' : undefined"
                    :aria-describedby="errors.password ? 'password-error' : undefined"
                />

                <InputError
                    id="password-error"
                    class="mt-2"
                    :message="errors.password"
                />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password_confirmation"
                    value="Confirm Password"
                />

                <TextInput
                    id="password_confirmation"
                    v-model="data.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                    :aria-invalid="errors.password_confirmation ? 'true' : undefined"
                    :aria-describedby="errors.password_confirmation ? 'password_confirmation-error' : undefined"
                />

                <InputError
                    id="password_confirmation-error"
                    class="mt-2"
                    :message="errors.password_confirmation"
                />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <RouterLink
                    :to="{ name: 'login', query: route.query }"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Already registered?
                </RouterLink>

                <PrimaryButton
                    class="ms-4"
                    :disabled="processing"
                >
                    Register
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
