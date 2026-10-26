<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import * as authApi from '@/api/auth';
import Checkbox from '@/components/Checkbox.vue';
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
    email: '',
    password: '',
    remember: false,
});

// 旧 props の `status`（flash）は、reset-password 成功時に router の history state で受け取る（§5.2）。
const passwordResetStatus = (() => {
    const status: unknown = router.options.history.state.status;

    return typeof status === 'string' ? status : null;
})();

// 未ログインでメール認証リンクを開くと、Laravel の callback がここへ送る（§5.4）。
const verificationRequired = computed(() => route.query.verification_required === '1');

async function onSubmit(): Promise<void> {
    await submit(authApi.login, {
        onSuccess: async (result) => {
            auth.setUser(result.data);
            await navigateAfterLogin(router, result.meta.redirect_to, route.query.redirect);
        },
    });

    reset('password');
}
</script>

<template>
    <GuestLayout>
        <div
            v-if="passwordResetStatus"
            role="status"
            class="mb-4 text-sm font-medium text-green-600"
        >
            {{ passwordResetStatus }}
        </div>

        <div
            v-if="verificationRequired"
            role="status"
            class="mb-4 text-sm font-medium text-gray-600"
        >
            メールアドレスの確認を完了するには、ログインしてください。
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
                    autocomplete="current-password"
                    :aria-invalid="errors.password ? 'true' : undefined"
                    :aria-describedby="errors.password ? 'password-error' : undefined"
                />

                <InputError
                    id="password-error"
                    class="mt-2"
                    :message="errors.password"
                />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox
                        v-model="data.remember"
                        name="remember"
                    />
                    <span class="ms-2 text-sm text-gray-600">Remember me</span>
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <RouterLink
                    :to="{ name: 'password.request' }"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Forgot your password?
                </RouterLink>

                <PrimaryButton
                    class="ms-4"
                    :disabled="processing"
                >
                    Log in
                </PrimaryButton>
            </div>
        </form>

        <p class="mt-4 text-center text-sm text-gray-600">
            <RouterLink
                :to="{ name: 'register', query: route.query }"
                class="rounded-md underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
                Create an account
            </RouterLink>
        </p>
    </GuestLayout>
</template>
