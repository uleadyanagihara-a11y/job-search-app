<script setup lang="ts">
import { ref } from 'vue';
import * as authApi from '@/api/auth';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import GuestLayout from '@/layouts/GuestLayout.vue';

const { data, errors, error, processing, submit } = useApiForm({ email: '' });

// 旧 props の `status`（flash）の代わりに、API の message を同じ画面に表示する（§5.2）。
// user の有無にかかわらず同じ message が返る（account enumeration 対策）。
const status = ref<string | null>(null);

async function onSubmit(): Promise<void> {
    status.value = null;

    await submit(authApi.sendPasswordResetLink, {
        onSuccess: (result) => {
            status.value = result.message;
        },
    });
}
</script>

<template>
    <GuestLayout>
        <div class="mb-4 text-sm text-gray-600">
            Forgot your password? No problem. Just let us know your email
            address and we will email you a password reset link that will allow
            you to choose a new one.
        </div>

        <div
            v-if="status"
            role="status"
            class="mb-4 text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

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
                    for="email"
                    value="Email"
                />

                <TextInput
                    id="email"
                    v-model="data.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autofocus
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

            <div class="mt-4 flex items-center justify-end">
                <RouterLink
                    :to="{ name: 'login' }"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Back to log in
                </RouterLink>

                <PrimaryButton
                    class="ms-4"
                    :disabled="processing"
                >
                    Email Password Reset Link
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
