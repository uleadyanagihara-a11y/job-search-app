<?php

return [

    'reset' => 'パスワードを再設定しました。',
    'sent' => 'パスワード再設定のリンクをメールで送信しました。',
    'throttled' => 'しばらく時間をおいて再度お試しください。',
    // reset-password では user・token・throttled のどれも errors.token に出す（§5.3）。
    // email の有無が分からないよう、user も token と同じ文にする。
    'token' => 'パスワード再設定のリンクが無効か、有効期限が切れています。',
    'user' => 'パスワード再設定のリンクが無効か、有効期限が切れています。',

];
