<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import * as authApi from '@/api/auth';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { safeRedirectPath } from '@/router/redirect';

const route = useRoute();
const router = useRouter();

const { data, errors, error, processing, submit, reset } = useApiForm({ password: '' });

async function onSubmit(): Promise<void> {
    await submit(authApi.confirmPassword, {
        // 423 の handler（main.ts）が `?redirect=` に元の画面を入れて送ってくる（§5.2）。
        // 戻る必要の無い確認画面を history に残さないよう replace する。
        onSuccess: async () => {
            await router.replace(safeRedirectPath(route.query.redirect) ?? { name: 'dashboard' });
        },
    });

    reset('password');
}
</script>

<template>
    <GuestLayout>
        <div class="mb-4 text-sm text-fg-muted">
            この操作を続けるには、パスワードを入力してください。
        </div>

        <p
            v-if="error"
            role="alert"
            class="mb-4 text-sm text-danger-600"
        >
            {{ error.message }}
        </p>

        <form @submit.prevent="onSubmit">
            <div>
                <InputLabel
                    for="password"
                    value="パスワード"
                />

                <TextInput
                    id="password"
                    v-model="data.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autofocus
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

            <div class="mt-4 flex flex-wrap justify-end gap-y-2">
                <PrimaryButton
                    class="ms-4"
                    :disabled="processing"
                >
                    確認
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
