<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use App\Models\User;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    private const UNBOUND_USER_HOURLY_LIMIT = 3;
    private const CACHE_PREFIX = 'telegram_unbound_user_';

    public function __construct(Request $request)
    {
        if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
    }

    public function webhook(Request $request)
    {
        $this->formatMessage($request->input());
        if ($this->checkAndKickChannelMessage()) {
            return;
        }

        $this->handle();
    }

    private function checkAndKickChannelMessage()
    {
        if (!$this->msg) {
            return false;
        }

        $msg = $this->msg;

        if (!$msg->is_channel_message || $msg->is_private) {
            return false;
        }

        try {
            if ($msg->sender_chat_id) {
                $chatInfo = $this->telegramService->getChat($msg->chat_id);
                $linkedChatId = $chatInfo->result->linked_chat_id ?? null;
                if ($linkedChatId && $linkedChatId == $msg->sender_chat_id) {
                    return true;
                }
            }
            $this->telegramService->deleteMessage($msg->chat_id, $msg->message_id);
            if ($msg->sender_chat_id) {
                $this->telegramService->banChatSenderChat($msg->chat_id, $msg->sender_chat_id);
            }
            $channelUsername = $msg->sender_chat_username ?? '未知频道';
            $text = "⚠️ 检测到频道 @{$channelUsername} 身份发言，消息已删除并已封禁该频道发言权限。";
            $this->telegramService->sendMessage($msg->chat_id, $text, 'HTML');
            return true;

        } catch (\Exception $e) {
            \Log::warning("[Telegram] 处理频道消息失败：" . $e->getMessage());
            return false;
        }
    }

    protected function kickUser(int $chatId, int $userId, ?int $banSeconds = null, bool $revokeMessages = true)
    {
        $untilDate = $banSeconds ? time() + $banSeconds : null;
        return $this->telegramService->banChatMember($chatId, $userId, $untilDate, $revokeMessages);
    }

    public function handle()
    {
        if (!$this->msg) return;

        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        $user = User::where('telegram_id', $msg->from->id ?? 0)
            ->where('banned', 0)
            ->first();

        if (!$user && !$msg->is_private) {
            if (!$this->checkUnboundUserLimit($msg)) {
                return;
            }
        }

        if (count($commandName) === 2) {
            $botName = $this->getBotName();
            if ($commandName[1] === $botName) {
                $msg->command = $commandName[0];
            }
        }

        try {
            foreach (glob(base_path('app/Plugins/Telegram/Commands/*.php')) as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;

                $instance = new $class();

                if ($msg->message_type === 'message') {
                    if (!isset($msg->command)) continue;

                    $input = $msg->command;

                    $matchesCommand = isset($instance->command) && $input === $instance->command;
                    $matchesKeyword = isset($instance->keywords) && in_array($input, $instance->keywords);

                    if (!$matchesCommand && !$matchesKeyword) continue;

                    if (substr($input, 0, 1) === '/') {
                        $this->telegramService->deleteMessage($msg->chat_id, $msg->message_id, 60);
                    }

                    $instance->handle($msg);
                    return;
                }

                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;

                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    private function checkUnboundUserLimit($msg): bool
    {
        if (!isset($msg->from->id)) return false;
    
        $userId = $msg->from->id;
        $chatId = $msg->chat_id;
        $cacheKey = self::CACHE_PREFIX . $userId;
    
        $currentCount = \Cache::get($cacheKey, 0);
        $newCount = $currentCount + 1;
        \Cache::put($cacheKey, $newCount, now()->endOfHour());
    
        try {
            $this->telegramService->deleteMessage($chatId, $msg->message_id);
            if ($newCount < self::UNBOUND_USER_HOURLY_LIMIT) {
                $permissions = [
                    'can_send_messages' => false,
                    'can_send_media_messages' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false,
                    'can_change_info' => false,
                    'can_invite_users' => false,
                    'can_pin_messages' => false,
                ];
                $this->sendBindReminder($msg, $newCount, 3600);
                $this->telegramService->restrictChatMember(
                    $chatId,
                    $userId,
                    $permissions,
                    time() + 3600,          
                    false
                );
            } else {
                \Cache::forget($cacheKey);
                $this->sendBindReminder($msg, $newCount, 86400, true);
                $this->telegramService->banChatMember(
                    $chatId,
                    $userId,
                    time() + 86400,         
                    true
                );
            }
        } catch (\Exception $e) {
            \Log::warning("[Telegram] 未绑定用户处理失败：" . $e->getMessage());
        }
        return false; // 阻止消息继续处理
    }
    
    
    private function sendBindReminder($msg, int $currentCount, int $banSeconds, bool $isKicked = false)
    {
        $userId = $msg->from->id;
        $chatId = $msg->chat_id;
        $username = $msg->from->username ?? $msg->from->first_name ?? '用户';
        $mention = '@' . $username;
        $botName = $this->getBotName();
    
        if ($isKicked) {
            $text = "🚫 {$mention} 未绑定账户，累计违规 ". self::UNBOUND_USER_HOURLY_LIMIT . "次，已被移出群组并禁言 24 小时。\n";
            $text .= "🔗 请私聊 @{$botName} 发送 /bind 订阅链接 绑定后再加入群组。";
        } else {
            $minutes = $banSeconds / 60;
            $text = "⚠️ {$mention} 您尚未绑定账户！\n";
            $text .= "⏱ 已被禁言 <b>{$minutes} 分钟</b>（当前累计违规 {$currentCount}/".self::UNBOUND_USER_HOURLY_LIMIT"次）。\n";
            $text .= "🔗 请私聊 @{$botName} 发送 /bind 订阅链接完成绑定，否则将被移出群组。";
        }
        $this->telegramService->sendMessage($chatId, $text, 'HTML', ['disable_web_page_preview' => true]);
    }
    
    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message']) || !isset($data['message']['text'])) return;

        $obj = new \StdClass();
        $text = preg_split('/\s+/', trim($data['message']['text']));
        $obj->command = $text[0] ?? '';
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        $obj->is_channel_message = false;
        $obj->sender_chat_username = null;
        $obj->sender_chat_id = null;

        if (isset($data['message']['sender_chat'])) {
            $senderChat = $data['message']['sender_chat'];
            if (($senderChat['type'] ?? '') === 'channel') {
                $obj->is_channel_message = true;
                $obj->sender_chat_id = $senderChat['id'] ?? null;
                $obj->sender_chat_username = $senderChat['username'] ?? $senderChat['title'] ?? null;
            }
        }

        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }

        if (isset($data['message']['from'])) {
            $obj->from = (object)[
                'id' => $data['message']['from']['id'] ?? null,
                'username' => $data['message']['from']['username'] ?? null,
                'first_name' => $data['message']['from']['first_name'] ?? null,
            ];
        }

        $this->msg = $obj;
    }
}
