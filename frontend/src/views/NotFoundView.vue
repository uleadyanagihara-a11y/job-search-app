<template>
    <section>
        <h1>404</h1>
        <p>ページが見つかりませんでした。</p>
        <RouterLink :to="{ name: 'home' }">
            トップへ戻る
        </RouterLink>
    </section>
</template>
