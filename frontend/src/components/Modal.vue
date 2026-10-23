<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, useTemplateRef, watch } from 'vue';

// 表示は親が `show` で制御する。閉じる操作（Escape、背景の click）は `close` を emit するだけで、
// 親が `show` を false にしたときに leave transition の後で dialog を閉じる。
const props = withDefaults(
    defineProps<{
        show?: boolean;
        maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
        closeable?: boolean;
    }>(),
    { show: false, maxWidth: '2xl', closeable: true },
);

const emit = defineEmits<{ close: [] }>();

// leave transition（duration-200）の長さ
const LEAVE_DURATION_MS = 200;

const dialog = useTemplateRef<HTMLDialogElement>('dialog');
const showSlot = ref(props.show);

let closeTimer: ReturnType<typeof setTimeout> | undefined;

function open(): void {
    // 閉じている途中で再び開いた場合に、遅れて dialog が閉じないようにする。
    clearTimeout(closeTimer);
    document.body.style.overflow = 'hidden';
    showSlot.value = true;

    if (dialog.value && !dialog.value.open) {
        dialog.value.showModal();
    }
}

function hide(): void {
    document.body.style.overflow = '';
    clearTimeout(closeTimer);
    closeTimer = setTimeout(() => {
        dialog.value?.close();
        showSlot.value = false;
    }, LEAVE_DURATION_MS);
}

watch(
    () => props.show,
    (show) => (show ? open() : hide()),
);

onMounted(() => {
    if (props.show) {
        open();
    }
});

onUnmounted(() => {
    clearTimeout(closeTimer);
    document.body.style.overflow = '';
});

function close(): void {
    if (props.closeable) {
        emit('close');
    }
}

const maxWidthClass = computed(
    () =>
        ({
            sm: 'sm:max-w-sm',
            md: 'sm:max-w-md',
            lg: 'sm:max-w-lg',
            xl: 'sm:max-w-xl',
            '2xl': 'sm:max-w-2xl',
        })[props.maxWidth],
);
</script>

<template>
    <!--
        Escape は keydown で既定動作（dialog の cancel → close）を止め、閉じるかどうかを親に任せる。
        keydown を経ない cancel（ブラウザの戻る操作等）も同様に扱う。
    -->
    <dialog
        ref="dialog"
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        @keydown.esc.prevent="close"
        @cancel.prevent="close"
    >
        <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    data-modal-backdrop
                    class="fixed inset-0 transform transition-all"
                    @click="close"
                >
                    <div class="absolute inset-0 bg-gray-500 opacity-75" />
                </div>
            </Transition>

            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <div
                    v-show="show"
                    class="mb-6 transform overflow-hidden rounded-lg bg-white shadow-xl transition-all sm:mx-auto sm:w-full"
                    :class="maxWidthClass"
                >
                    <slot v-if="showSlot" />
                </div>
            </Transition>
        </div>
    </dialog>
</template>
