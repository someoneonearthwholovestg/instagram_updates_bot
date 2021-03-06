<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\InstagramProfileHelper;
use Telegram\Bot\Laravel\Facades\Telegram;

class UpdatesController extends Controller
{
    use InstagramProfileHelper;

    /**
     * Webhook handler.
     *
     * @return String
     */
    public function updates(): string
    {
        $updates = Telegram::commandsHandler(true);

        $pattern = '/https?:\/\/(www\.)?instagram\.com\/([A-Za-z0-9_](?:(?:[A-Za-z0-9_]|(?:\.(?!\.))){0,28}(?:[A-Za-z0-9_]))?)/';

        foreach ($updates as $update) {
            if (is_array($update) and array_key_exists('text', $update) and preg_match($pattern, $update['text']) and $update['from']['id']) {
                $user = User::where('telegram_id', '=', $update['from']['id'])->first();

                if ($user) {
                    $response = self::addProfile($user, $update['text']);
                    $message = implode(', ', $response['messages']);

                    if ($response['status']) {
                        Telegram::sendMessage([
                            'chat_id'    => $update['from']['id'],
                            'text'       => "🤖 `OK! {$message}.`",
                            'parse_mode' => 'Markdown'
                        ]);
                    } else {
                        Telegram::sendMessage([
                            'chat_id'    => $update['from']['id'],
                            'text'       => "🤖 `ERROR! {$message}.`",
                            'parse_mode' => 'Markdown'
                        ]);
                    }

                } else {
                    Telegram::sendMessage([
                        'chat_id'    => $update['from']['id'],
                        'text'       => "🤖 `I can't find your account. You have to subscribe on " . env('APP_URL') . "`",
                        'parse_mode' => 'Markdown'
                    ]);
                }
            }
        }

        return 'ok';
    }
}
