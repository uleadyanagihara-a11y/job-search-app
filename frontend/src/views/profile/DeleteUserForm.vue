<script setup lang="ts">
import { nextTick, ref, useTemplateRef } from 'vue';
import { useRouter } from 'vue-router';
import * as profileApi from '@/api/profile';
import DangerButton from '@/components/DangerButton.vue';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import Modal from '@/components/Modal.vue';
import SecondaryButton from '@/components/SecondaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

const confirmingUserDeletion = ref(false);
const passwordInput = useTemplateRef<InstanceType<typeof TextInput>>('passwordInput');

const { data, errors, error, processing, submit, reset, clearErrors } = useApiForm({ password: '' });

async function confirmUserDeletion(): Promise<void> {
    confirmingUserDeletion.value = true;

    await nextTick();
    passwordInput.value?.focus();
}

async function deleteUser(): Promise<void> {
    const deleted = await submit(profileApi.deleteAccount, {
        // server 側で logout・session 無効化済み。auth store を破棄して login へ送る
        // （`/` は dashboard への redirect で、不要な `?redirect=` が付くため。§6.1）。
        // login は guest 用なので、先に破棄しないと guard に dashboard へ戻される。
        onSuccess: async () => {
            auth.clear();
            await router.replace({ name: 'login' });
        },
    });

    // password は成否にかかわらず消す（旧 onFinish の reset）。
    reset();

    if (!deleted) {
        passwordInput.value?.focus();
    }
}

function closeModal(): void {
    confirmingUserDeletion.value = false;

    clearErrors();
    error.value = null;
    reset();
}
</script>

<template>
    <!--
        space-y-* は使わない。Tailwind CSS 4 の space-y は「最後以外の子」に margin-bottom を付けるため、
        最後の子が閉じた Modal（dialog）だとボタンの下に余白ができる。
    -->
    <section>
        <header>
            <h2 class="text-lg font-medium text-fg-strong">
                Delete Account
            </h2>

            <p class="mt-1 text-sm text-fg-muted">
                Once your account is deleted, all of its resources and data will
                be permanently deleted. Before deleting your account, please
                download any data or information that you wish to retain.
            </p>
        </header>

        <DangerButton
            class="mt-6"
            @click="confirmUserDeletion"
        >
            Delete Account
        </DangerButton>

        <!-- attrs は Modal の root（dialog）に付く。 -->
        <Modal
            :show="confirmingUserDeletion"
            aria-labelledby="delete-user-title"
            @close="closeModal"
        >
            <!-- 旧 @keyup.enter の代わりに form の submit で Enter を受ける。 -->
            <form
                class="p-6"
                @submit.prevent="deleteUser"
            >
                <h2
                    id="delete-user-title"
                    class="text-lg font-medium text-fg-strong"
                >
                    Are you sure you want to delete your account?
                </h2>

                <p class="mt-1 text-sm text-fg-muted">
                    Once your account is deleted, all of its resources and data
                    will be permanently deleted. Please enter your password to
                    confirm you would like to permanently delete your account.
                </p>

                <p
                    v-if="error"
                    role="alert"
                    class="mt-6 text-sm text-danger-600"
                >
                    {{ error.message }}
                </p>

                <div class="mt-6">
                    <InputLabel
                        for="delete_user_password"
                        value="Password"
                        class="sr-only"
                    />

                    <TextInput
                        id="delete_user_password"
                        ref="passwordInput"
                        v-model="data.password"
                        type="password"
                        class="mt-1 block w-3/4"
                        placeholder="Password"
                        autocomplete="current-password"
                        :aria-invalid="errors.password ? 'true' : undefined"
                        :aria-describedby="errors.password ? 'delete_user_password-error' : undefined"
                    />

                    <InputError
                        id="delete_user_password-error"
                        class="mt-2"
                        :message="errors.password"
                    />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">
                        Cancel
                    </SecondaryButton>

                    <DangerButton
                        type="submit"
                        class="ms-3"
                        :disabled="processing"
                    >
                        Delete Account
                    </DangerButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
