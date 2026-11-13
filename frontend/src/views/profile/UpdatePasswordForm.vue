<script setup lang="ts">
import { useTemplateRef } from 'vue';
import * as profileApi from '@/api/profile';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';

// id は account 削除の Modal の password 欄と重ならないよう `update_password_` を前に付ける
// （Breeze は両方 `password` で、label の for が重複していた）。

const currentPasswordInput = useTemplateRef<InstanceType<typeof TextInput>>('currentPasswordInput');
const passwordInput = useTemplateRef<InstanceType<typeof TextInput>>('passwordInput');

const { data, errors, error, processing, recentlySuccessful, submit, reset } = useApiForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

async function onSubmit(): Promise<void> {
    if (await submit(profileApi.updatePassword)) {
        reset();
        return;
    }

    // 誤った欄だけ消して focus する（両方なら current_password を優先して focus）。
    // 422 以外の失敗（network、429 等）は入力を残し、そのまま再送できるようにする。
    if (errors.value.password) {
        reset('password', 'password_confirmation');
        passwordInput.value?.focus();
    }

    if (errors.value.current_password) {
        reset('current_password');
        currentPasswordInput.value?.focus();
    }
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-fg-strong">
                パスワードの変更
            </h2>

            <p class="mt-1 text-sm text-fg-muted">
                安全のため、長く推測されにくいパスワードを設定してください。
            </p>
        </header>

        <p
            v-if="error"
            role="alert"
            class="mt-6 text-sm text-danger-600"
        >
            {{ error.message }}
        </p>

        <form
            class="mt-6 space-y-6"
            @submit.prevent="onSubmit"
        >
            <div>
                <InputLabel
                    for="update_password_current_password"
                    value="現在のパスワード"
                />

                <TextInput
                    id="update_password_current_password"
                    ref="currentPasswordInput"
                    v-model="data.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    :aria-invalid="errors.current_password ? 'true' : undefined"
                    :aria-describedby="errors.current_password ? 'update_password_current_password-error' : undefined"
                />

                <InputError
                    id="update_password_current_password-error"
                    class="mt-2"
                    :message="errors.current_password"
                />
            </div>

            <div>
                <InputLabel
                    for="update_password_password"
                    value="新しいパスワード"
                />

                <TextInput
                    id="update_password_password"
                    ref="passwordInput"
                    v-model="data.password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :aria-invalid="errors.password ? 'true' : undefined"
                    :aria-describedby="errors.password ? 'update_password_password-error' : undefined"
                />

                <InputError
                    id="update_password_password-error"
                    class="mt-2"
                    :message="errors.password"
                />
            </div>

            <div>
                <InputLabel
                    for="update_password_password_confirmation"
                    value="新しいパスワード（確認）"
                />

                <TextInput
                    id="update_password_password_confirmation"
                    v-model="data.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :aria-invalid="errors.password_confirmation ? 'true' : undefined"
                    :aria-describedby="
                        errors.password_confirmation ? 'update_password_password_confirmation-error' : undefined
                    "
                />

                <InputError
                    id="update_password_password_confirmation-error"
                    class="mt-2"
                    :message="errors.password_confirmation"
                />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="processing">
                    保存
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="recentlySuccessful"
                        role="status"
                        class="text-sm text-fg-muted"
                    >
                        保存しました。
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
