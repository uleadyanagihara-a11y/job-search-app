<script setup lang="ts">
import * as seekerProfileApi from '@/api/seekerProfile';
import AlertMessage from '@/components/AlertMessage.vue';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import MutedText from '@/components/MutedText.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import SavedStatus from '@/components/SavedStatus.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextareaInput from '@/components/TextareaInput.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import { PREFECTURES } from '@/constants/prefectures';
import type { SeekerProfile } from '@/types/api';

const props = defineProps<{ profile: SeekerProfile }>();

// 入力欄は文字列で持ち、送るときに API の型（未入力は null、都道府県は番号）へ変える。
const { data, errors, error, processing, recentlySuccessful, submit } = useApiForm({
    desired_prefecture: props.profile.desired_prefecture?.toString() ?? '',
    desired_job_type: props.profile.desired_job_type ?? '',
    self_pr: props.profile.self_pr ?? '',
});

function toPayload(input: typeof data): seekerProfileApi.SeekerProfileInput {
    return {
        desired_prefecture: input.desired_prefecture === '' ? null : Number(input.desired_prefecture),
        desired_job_type: input.desired_job_type.trim() === '' ? null : input.desired_job_type,
        self_pr: input.self_pr.trim() === '' ? null : input.self_pr,
    };
}

async function onSubmit(): Promise<void> {
    await submit((input) => seekerProfileApi.updateSeekerProfile(toPayload(input)));
}
</script>

<template>
    <form
        class="space-y-6"
        @submit.prevent="onSubmit"
    >
        <AlertMessage v-if="error">
            {{ error.message }}
        </AlertMessage>

        <div>
            <InputLabel
                for="desired_prefecture"
                value="希望勤務地"
            />

            <SelectInput
                id="desired_prefecture"
                v-model="data.desired_prefecture"
                class="mt-1 block w-full"
                :aria-invalid="errors.desired_prefecture ? 'true' : undefined"
                :aria-describedby="errors.desired_prefecture ? 'desired_prefecture-error' : undefined"
            >
                <option value="">
                    選択しない
                </option>
                <option
                    v-for="prefecture in PREFECTURES"
                    :key="prefecture.code"
                    :value="String(prefecture.code)"
                >
                    {{ prefecture.name }}
                </option>
            </SelectInput>

            <InputError
                id="desired_prefecture-error"
                class="mt-2"
                :message="errors.desired_prefecture"
            />
        </div>

        <div>
            <InputLabel
                for="desired_job_type"
                value="希望職種"
            />

            <TextInput
                id="desired_job_type"
                v-model="data.desired_job_type"
                type="text"
                class="mt-1 block w-full"
                placeholder="例: Web エンジニア、営業"
                :aria-invalid="errors.desired_job_type ? 'true' : undefined"
                :aria-describedby="errors.desired_job_type ? 'desired_job_type-error' : 'desired_job_type-hint'"
            />

            <MutedText
                id="desired_job_type-hint"
                class="mt-1"
            >
                100 文字まで
            </MutedText>

            <InputError
                id="desired_job_type-error"
                class="mt-2"
                :message="errors.desired_job_type"
            />
        </div>

        <div>
            <InputLabel
                for="self_pr"
                value="自己 PR"
            />

            <TextareaInput
                id="self_pr"
                v-model="data.self_pr"
                rows="8"
                class="mt-1 block w-full"
                :aria-invalid="errors.self_pr ? 'true' : undefined"
                :aria-describedby="errors.self_pr ? 'self_pr-error' : 'self_pr-hint'"
            />

            <MutedText
                id="self_pr-hint"
                class="mt-1"
            >
                2000 文字まで。経験やスキル、希望する働き方などを書いてください。
            </MutedText>

            <InputError
                id="self_pr-error"
                class="mt-2"
                :message="errors.self_pr"
            />
        </div>

        <div class="flex items-center gap-4">
            <PrimaryButton :disabled="processing">
                保存
            </PrimaryButton>

            <SavedStatus :show="recentlySuccessful" />
        </div>
    </form>
</template>
