import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Modal from '@/components/Modal.vue';

// jsdom は showModal/close を実装していないため、open 属性の切替だけ再現する。
// jsdom は test file ごとに作られるので、この file の外には影響しない。
HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.open = true;
};
HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    this.open = false;
};

function mountModal(props: { show: boolean; closeable?: boolean }) {
    return mount(Modal, {
        props,
        slots: { default: '<p>本当に削除しますか？</p>' },
        attachTo: document.body,
    });
}

describe('Modal', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('show=false では dialog を開かず slot も描画しない', () => {
        const wrapper = mountModal({ show: false });

        expect(wrapper.get('dialog').element.open).toBe(false);
        expect(wrapper.text()).not.toContain('本当に削除しますか？');
    });

    it('show=true で mount すると dialog を開き body のスクロールを止める', () => {
        const wrapper = mountModal({ show: true });

        expect(wrapper.get('dialog').element.open).toBe(true);
        expect(wrapper.text()).toContain('本当に削除しますか？');
        expect(document.body.style.overflow).toBe('hidden');
    });

    it('show を false にすると leave transition の後に dialog を閉じる', async () => {
        const wrapper = mountModal({ show: true });

        await wrapper.setProps({ show: false });

        expect(document.body.style.overflow).toBe('');
        expect(wrapper.get('dialog').element.open).toBe(true);

        vi.advanceTimersByTime(200);
        await wrapper.vm.$nextTick();

        expect(wrapper.get('dialog').element.open).toBe(false);
        expect(wrapper.text()).not.toContain('本当に削除しますか？');
    });

    it('閉じている途中で再び開いたら、遅れて閉じない', async () => {
        const wrapper = mountModal({ show: true });

        await wrapper.setProps({ show: false });
        await wrapper.setProps({ show: true });
        vi.advanceTimersByTime(200);
        await wrapper.vm.$nextTick();

        expect(wrapper.get('dialog').element.open).toBe(true);
        expect(wrapper.text()).toContain('本当に削除しますか？');
    });

    it('Escape・cancel・背景の click で close を emit し、dialog は自分で閉じない', async () => {
        const wrapper = mountModal({ show: true });
        const dialog = wrapper.get('dialog');

        await dialog.trigger('keydown', { key: 'Escape' });
        const cancel = new Event('cancel', { cancelable: true });
        dialog.element.dispatchEvent(cancel);
        await wrapper.get('[data-modal-backdrop]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(3);
        expect(cancel.defaultPrevented).toBe(true);
        expect(dialog.element.open).toBe(true);
    });

    it('closeable=false なら close を emit しない', async () => {
        const wrapper = mountModal({ show: true, closeable: false });

        await wrapper.get('dialog').trigger('keydown', { key: 'Escape' });
        await wrapper.get('[data-modal-backdrop]').trigger('click');

        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('unmount で body のスクロールを戻す', () => {
        const wrapper = mountModal({ show: true });

        wrapper.unmount();

        expect(document.body.style.overflow).toBe('');
    });
});
