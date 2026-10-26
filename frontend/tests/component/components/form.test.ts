import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Checkbox from '@/components/Checkbox.vue';
import DangerButton from '@/components/DangerButton.vue';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import SecondaryButton from '@/components/SecondaryButton.vue';
import TextInput from '@/components/TextInput.vue';

describe('TextInput', () => {
    it('v-model で値を双方向に受け渡す', async () => {
        const wrapper = mount(TextInput, {
            props: {
                modelValue: 'a@example.com',
                'onUpdate:modelValue': (value: string) => wrapper.setProps({ modelValue: value }),
            },
        });

        expect(wrapper.get('input').element.value).toBe('a@example.com');

        await wrapper.get('input').setValue('b@example.com');

        expect(wrapper.props('modelValue')).toBe('b@example.com');
    });

    it('autofocus 属性があれば mount 時に focus する', () => {
        const wrapper = mount(TextInput, {
            props: { modelValue: '' },
            attrs: { autofocus: '' },
            attachTo: document.body,
        });

        expect(document.activeElement).toBe(wrapper.get('input').element);
    });

    it('expose した focus() で focus できる', () => {
        const wrapper = mount(TextInput, { props: { modelValue: '' }, attachTo: document.body });

        wrapper.vm.focus();

        expect(document.activeElement).toBe(wrapper.get('input').element);
    });

    it('type や id などの attrs は input に付く', () => {
        const wrapper = mount(TextInput, {
            props: { modelValue: '' },
            attrs: { id: 'password', type: 'password' },
        });

        expect(wrapper.get('input').attributes()).toMatchObject({ id: 'password', type: 'password' });
    });
});

describe('Checkbox', () => {
    it('v-model の boolean を checked に反映し、変更を emit する', async () => {
        const wrapper = mount(Checkbox, { props: { modelValue: false } });

        expect(wrapper.get('input').element.checked).toBe(false);

        await wrapper.get('input').setValue(true);

        expect(wrapper.emitted('update:modelValue')).toEqual([[true]]);
    });
});

describe('InputLabel', () => {
    it('value があれば value、無ければ slot を表示する', () => {
        expect(mount(InputLabel, { props: { value: 'Email' } }).text()).toBe('Email');
        expect(mount(InputLabel, { slots: { default: 'Password' } }).text()).toBe('Password');
    });

    it('for は label に付く', () => {
        const wrapper = mount(InputLabel, { props: { value: 'Email' }, attrs: { for: 'email' } });

        expect(wrapper.get('label').attributes('for')).toBe('email');
    });
});

describe('InputError', () => {
    it('message があるときだけ表示する', async () => {
        const wrapper = mount(InputError, { props: { message: undefined }, attachTo: document.body });

        expect(wrapper.isVisible()).toBe(false);

        await wrapper.setProps({ message: 'The email field is required.' });

        expect(wrapper.isVisible()).toBe(true);
        expect(wrapper.text()).toBe('The email field is required.');
    });
});

describe('buttons', () => {
    it('PrimaryButton は既定で submit、他は button', () => {
        expect(mount(PrimaryButton).get('button').attributes('type')).toBe('submit');
        expect(mount(SecondaryButton).get('button').attributes('type')).toBe('button');
        expect(mount(DangerButton).get('button').attributes('type')).toBe('button');
    });

    it('type と disabled を指定できる', () => {
        const wrapper = mount(PrimaryButton, {
            props: { type: 'button' },
            attrs: { disabled: true },
            slots: { default: 'Log in' },
        });

        expect(wrapper.get('button').attributes('type')).toBe('button');
        expect(wrapper.get('button').element.disabled).toBe(true);
        expect(wrapper.text()).toBe('Log in');
    });
});
