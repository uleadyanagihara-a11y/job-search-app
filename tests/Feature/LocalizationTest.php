<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日本語化（docs/domain-design.md 14 章）。文言そのものは lang/ja にあり、ここでは
 * 訳が読まれていること（英語の既定文に戻っていないこと）を代表的な箇所で確かめる。
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_messages_use_japanese_attribute_names(): void
    {
        $response = $this->fromFrontend()->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.name.0', '名前を入力してください。');
        $response->assertJsonPath('errors.email.0', 'メールアドレスには有効なメールアドレスを指定してください。');
        $this->assertContains('パスワードと確認用の入力が一致しません。', $response->json('errors.password'));
        $this->assertContains('パスワードは8文字以上で入力してください。', $response->json('errors.password'));
    }

    public function test_unique_email_message_is_japanese(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->fromFrontend()->postJson('/api/auth/register', [
            'name' => '山田 太郎',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertJsonPath('errors.email.0', 'このメールアドレスは既に使われています。');
    }

    public function test_failed_login_message_is_japanese(): void
    {
        $user = User::factory()->create();

        $response = $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertJsonPath('errors.email.0', 'メールアドレスまたはパスワードが正しくありません。');
    }

    public function test_reset_error_does_not_reveal_whether_the_email_exists(): void
    {
        $user = User::factory()->create();
        $payload = ['token' => 'invalid', 'password' => 'new-password', 'password_confirmation' => 'new-password'];

        $known = $this->fromFrontend()->postJson('/api/auth/reset-password', $payload + ['email' => $user->email]);
        $unknown = $this->fromFrontend()->postJson('/api/auth/reset-password', $payload + ['email' => 'nobody@example.com']);

        $known->assertJsonPath('errors.token.0', 'パスワード再設定のリンクが無効か、有効期限が切れています。');
        $this->assertSame($known->json('errors'), $unknown->json('errors'));
    }

    public function test_verification_mail_is_japanese(): void
    {
        $user = User::factory()->unverified()->create();

        $mail = (new VerifyEmail)->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('メールアドレスの確認', $mail->subject);
        $this->assertSame('メールアドレスを確認する', $mail->actionText);
        $this->assertStringContainsString(' lang="ja">', $html);
        $this->assertStringContainsString('こんにちは', $html);
        $this->assertStringContainsString('ボタンを押せない場合は', $html);
    }

    public function test_password_reset_mail_is_japanese(): void
    {
        $user = User::factory()->create();

        $mail = (new ResetPassword('token'))->toMail($user);

        $this->assertSame('パスワードの再設定', $mail->subject);
        $this->assertSame('パスワードを再設定する', $mail->actionText);
        $this->assertContains('このリンクの有効期限は60分です。', $mail->outroLines);
    }
}
