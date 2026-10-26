<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import * as authApi from '@/api/auth';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import GuestLayout from '@/layouts/GuestLayout.vue';

// mail の URL は /reset-password/{token}?email=...（§5.3）。旧 props の token/email を route から取る。
// token の有効性は表示時には確かめず、submit 時の Laravel の検証を正とする。
const route = useRoute();
const router = useRouter();

const token = route.params.token;
const email = route.query.email;

const { data, errors, error, processing, submit, reset } = useApiForm({
    token: typeof token === 'string' ? token : '',
    email: typeof email === 'string' ? email : '',
    password: '',
    password_confirmation: '',
});

async function onSubmit(): Promise<void> {
    await submit(authApi.resetPassword, {
        onSuccess: async (result) => {
            // token/email を含む URL へ戻れないよう replace し、message は history state で login に渡す。
            await router.replace({ name: 'login', state: { status: result.message } });
        },
    });

    reset('password', 'password_confirmation');
}
</script>

<template>
    <GuestLayout>
        <!-- 無効・期限切れ・使用済みの token は errors.token で返る（入力欄は無い） -->
        <InputError
            id="token-error"
            role="alert"
            class="mb-4"
            :message="errors.token"
        />

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
                    :to="{ name: 'password.request' }"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Request a new link
                </RouterLink>

                <PrimaryButton
                    class="ms-4"
                    :disabled="processing"
                >
                    Reset Password
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
