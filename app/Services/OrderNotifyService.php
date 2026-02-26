<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\Coupon;
use App\Services\TelegramService;
use Illuminate\Support\Carbon;

class OrderNotifyService
{
    public function notify(Order $order): void
    {
        if (!config('v2board.telegram_bot_order_notify', 0))
        return;
        // type
        $types = [1 => "新购", 2 => "续费", 3 => "变更", 4 => "流量包", 5 => "兑换", 6 => "推广赠送"];
        $type = $types[$order->type] ?? "未知";

        // planName
        $planName = Plan::find($order->plan_id)?->name ?? '';

        // period
        $periodMapping = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '2年付',
            'three_year_price' => '3年付',
            'onetime_price' => '一次性付款',
            'setup_price' => '设置费',
            'reset_price' => '流量重置包'
        ];
        $period = $periodMapping[$order->period] ?? '';
        $gift_days = $order->gift_days;

        // user
        $user = User::find($order->user_id);
        $userEmail = $user?->email ?? '';

        // inviterEmail + commission
        $inviterEmail = '';
        $getAmount = 0;
        $anotherInfo = "";
        if (!empty($order->invite_user_id)) {
            $inviter = User::find($order->invite_user_id);
            if ($inviter) {
                $inviterEmail = $inviter->email;
                $getAmount = $this->getCommission($inviter->id, $order);
                if ((int)config('v2board.withdraw_close_enable', 0)) {
                    $inviterBalance = $inviter->balance / 100 + $getAmount;
                    $anotherInfo = "邀请人总余额：" . $inviterBalance . " 元";
                } else {
                    $inviterCommissionBalance = $inviter->commission_balance / 100 + $getAmount;
                    $anotherInfo = "邀请人总佣金：" . $inviterCommissionBalance . " 元";
                }
            }
        }

        // coupon
        $code = '';
        if ($order->coupon_id !== null) {
            $coupon = Coupon::find($order->coupon_id);
            if ($coupon) {
                $code = $coupon->code;
            }
        }

        // signup date
        $signupDate = $user?->created_at
            ? Carbon::createFromTimestamp($user->created_at)->toDateString()
            : '未知';

        // 收入统计（Asia/Shanghai、Unix 时间戳、仅统计已支付状态 3/4）
        $tz = config('app.timezone', 'Asia/Shanghai');
        $now = Carbon::now($tz);

        // 今日
        $startOfDayTs = $now->copy()->startOfDay()->timestamp;
        $endOfDayTs   = $now->copy()->endOfDay()->timestamp;

        $todayIncome = Order::whereIn('status', [3])
            ->whereBetween('paid_at', [$startOfDayTs, $endOfDayTs])
            ->sum('total_amount') / 100;

        // 本月
        $startOfMonthTs = $now->copy()->startOfMonth()->timestamp;
        $endOfMonthTs   = $now->copy()->endOfMonth()->timestamp;

        $monthIncome = Order::whereIn('status', [3])
            ->whereBetween('paid_at', [$startOfMonthTs, $endOfMonthTs])
            ->sum('total_amount') / 100;

        // message
        $lines = [];
        $lines[] = "💰成功收款 " . ($order->total_amount / 100) . " 元";
        $lines[] = "———————————————";
        $lines[] = "订单号：`{$order->trade_no}`";
        $lines[] = "邮箱：`{$userEmail}`";
        $lines[] = "套餐：{$planName}";
        $lines[] = "类型：{$type}";
        if (!empty($period)) $lines[] = "周期：{$period}";
        if (!empty($gift_days)) $lines[] = "赠送天数：{$gift_days}";
        if (!empty($code)) $lines[] = "优惠码：{$code}";
        if (!empty($getAmount)) $lines[] = "本次佣金：{$getAmount} 元";
        if (!empty($inviterEmail)) $lines[] = "邀请人邮箱：`{$inviterEmail}`";
        if (!empty($anotherInfo)) $lines[] = $anotherInfo;
        $lines[] = "注册日期：{$signupDate}";
        $lines[] = "———————————————";
        $lines[] = "📊 今日收入：{$todayIncome} 元";
        $lines[] = "📅 本月收入：{$monthIncome} 元";

        $message = implode("\n", $lines);

        (new TelegramService())->sendMessageWithAdmin($message, true);
    }

    private function getCommission($inviteUserId, $order)
    {
        $getAmount = 0;
        $level = 3;
        if ((int)config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [0 => 100];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            if (!$inviter) break;
            if (!isset($commissionShareLevels[$l])) break;
            $commissionBalance = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if ($commissionBalance) {
                $getAmount += $commissionBalance;
            }
            $inviteUserId = $inviter->invite_user_id;
            if (!$inviteUserId) break;
        }
        return $getAmount / 100;
    }
}
