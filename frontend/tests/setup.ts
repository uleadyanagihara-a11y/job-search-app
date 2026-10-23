import { enableAutoUnmount } from '@vue/test-utils';
import { afterEach } from 'vitest';

// mount した component を test ごとに破棄し、次の test に DOM や watcher を残さない。
enableAutoUnmount(afterEach);

afterEach(() => {
    // API client は document.cookie の XSRF-TOKEN を読むため、test 間で持ち越さない。
    for (const cookie of document.cookie.split('; ')) {
        const name = cookie.split('=')[0];

        if (name) {
            document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
        }
    }
});
