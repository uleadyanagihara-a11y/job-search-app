<?php

namespace App\Console\Commands;

use App\Mail\TestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class VerifyMail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:verify
                            {--to=dev-inbox@example.test : 送信先アドレス}
                            {--subject= : 件名（未指定なら自動生成）}
                            {--api=http://mailpit:8025 : Mailpit API のベース URL（コンテナ内から）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Laravel から Mailpit へテストメールを送り、宛先・件名・本文を Mailpit API で照合する';

    public function handle(): int
    {
        $to = (string) $this->option('to');
        $token = (string) Str::uuid();
        $subject = (string) ($this->option('subject') ?: 'メール送信経路の確認 '.now()->format('Y-m-d H:i:s'));
        $apiBase = rtrim((string) $this->option('api'), '/');

        $this->line('MAIL_MAILER: '.Config::get('mail.default'));
        $this->line('MAIL_HOST:   '.Config::get('mail.mailers.smtp.host').':'.Config::get('mail.mailers.smtp.port'));
        $this->line('From:        '.Config::get('mail.from.address'));
        $this->line('To:          '.$to);
        $this->line('Subject:     '.$subject);
        $this->line('Token:       '.$token);
        $this->newLine();

        try {
            Mail::to($to)->send(new TestMail($subject, $token));
            $this->info('送信: OK');

            $message = $this->pollForMessage($apiBase, $token);

            if ($message === null) {
                throw new \RuntimeException('送信したメールが Mailpit で見つかりませんでした。');
            }

            $recipients = collect($message['To'] ?? [])->pluck('Address')->all();

            if (! in_array($to, $recipients, true)) {
                throw new \RuntimeException('宛先が一致しません: '.implode(', ', $recipients));
            }

            $this->line('宛先一致:   '.implode(', ', $recipients));

            if (($message['Subject'] ?? null) !== $subject) {
                throw new \RuntimeException('件名が一致しません: '.($message['Subject'] ?? '(なし)'));
            }

            $this->line('件名一致:   '.$message['Subject']);

            $body = $this->fetchBody($apiBase, (string) $message['ID']);

            if (! str_contains($body, $token)) {
                throw new \RuntimeException('本文に照合トークンが含まれていません。');
            }

            $this->line('本文一致:   照合トークンを本文に確認');
            $this->newLine();
            $this->info('Mailpit への送信経路を確認しました。');
            $this->line('Mailpit UI: http://localhost:8026/  （最新メールを開いて宛先・件名・本文を目視確認できます）');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('メール確認に失敗しました: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pollForMessage(string $apiBase, string $token): ?array
    {
        $deadline = microtime(true) + 10;

        do {
            $response = Http::acceptJson()->get($apiBase.'/api/v1/search', [
                'query' => $token,
                'limit' => 5,
            ]);

            if ($response->successful()) {
                $messages = $response->json('messages', []);

                if (! empty($messages)) {
                    return $messages[0];
                }
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function fetchBody(string $apiBase, string $id): string
    {
        $response = Http::acceptJson()->get($apiBase.'/api/v1/message/'.$id);

        if (! $response->successful()) {
            throw new \RuntimeException('Mailpit からメール本文を取得できませんでした。');
        }

        return (string) $response->json('HTML', '').(string) $response->json('Text', '');
    }
}
