<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\SellerPaymentClaim;
use App\Models\SidebarSeen;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar/topbar badge counts. Every count belongs to ONE sub-menu item
 * (the app shell sums children onto a collapsed parent):
 *
 *   orders_list          → Orders → Order List        (watermarked)
 *   delivery_ready       → Delivery → Ready to Ship   (watermarked)
 *   messages_inbox       → Messages → Inbox           (real unread, no watermark)
 *   purchases_toreceive  → Purchase → Purchases       (watermarked)
 *   work_control         → Work Inbox → Work Control  (watermarked)
 *   sellers_pending      → Sellers → Seller Verification, platform only (watermarked)
 *   notifications_unread → topbar bell                (real read state)
 *
 * A watermarked count = matching rows that arrived after the user's
 * sidebar_seen watermark for that key (no row → everything counts, as
 * before this feature). Visiting the sub-menu page posts the watermark
 * to now and the badge clears until something new arrives. The
 * watermark is a (seen_at, seen_id) pair: timestamps are stored at
 * second precision, so a row created in the same second as the visit
 * is still recognised as new by its higher id.
 */
class SidebarCountsService
{
    /** Keys that use the seen-watermark (POST /sidebar-counts/seen). */
    public const WATERMARKED = [
        'orders_list',
        'delivery_ready',
        'purchases_toreceive',
        'work_control',
        'sellers_pending',
    ];

    public function isWatermarked(string $key): bool
    {
        return in_array($key, self::WATERMARKED, true);
    }

    /** Keep only rows that arrived after the key's watermark. */
    private function afterWatermark(Builder $query, ?SidebarSeen $seen, string $column = 'created_at'): Builder
    {
        if (! $seen) {
            return $query;
        }

        return $query->where(function (Builder $w) use ($seen, $column) {
            $w->where($column, '>', $seen->seen_at)
                ->orWhere(fn (Builder $w2) => $w2->where($column, $seen->seen_at)->where('id', '>', $seen->seen_id));
        });
    }

    /**
     * @param  ?int  $bizOverride  the viewed shop in view-as-seller
     *                             (ধাপ ৩): shop counts resolve that
     *                             shop, while the seen watermarks stay
     *                             the signed-in user's own rows.
     * @return array<string, int>
     */
    public function countsFor(?User $user, ?int $bizOverride = null): array
    {
        $biz = $bizOverride ?? $user?->business_id;
        $seen = ($user && Schema::hasTable('sidebar_seen'))
            ? SidebarSeen::where('user_id', $user->id)->get()->keyBy('key')
            : collect();

        $hasOrders = $biz && Schema::hasTable('orders');

        return [
            'orders_list' => $hasOrders
                ? $this->afterWatermark(Order::where('business_id', $biz)->where('status', 'Pending'), $seen['orders_list'] ?? null)->count()
                : 0,
            // Approximation: orders carry no "entered Processing" stamp,
            // so updated_at stands in for it — a Processing order edited
            // after the watermark re-surfaces the badge. Accepted: the
            // badge means "something changed here since you looked".
            'delivery_ready' => $hasOrders
                ? $this->afterWatermark(Order::where('business_id', $biz)->where('status', 'Processing'), $seen['delivery_ready'] ?? null, 'updated_at')->count()
                : 0,
            'notifications_unread' => $user && Schema::hasTable('app_notifications') && Schema::hasColumn('app_notifications', 'business_id')
                ? AppNotification::visibleTo($user)->whereNull('read_at')->count()
                : 0,
            'messages_inbox' => $biz && Schema::hasTable('conversations')
                ? (int) Conversation::where('business_id', $biz)->sum('unread_count')
                    + ChatMessage::where('business_id', $biz)->whereIn('status', ['draft', 'flagged'])->count()
                : 0,
            'purchases_toreceive' => $biz && Schema::hasTable('purchases')
                ? $this->afterWatermark(
                    Purchase::where('business_id', $biz)
                        ->whereHas('items', fn ($q) => $q->whereRaw('qty_received + qty_damaged < qty_ordered')),
                    $seen['purchases_toreceive'] ?? null,
                )->count()
                : 0,
            'work_control' => $biz && Schema::hasTable('work_tasks')
                ? $this->afterWatermark(WorkTask::where('business_id', $biz)->whereIn('status', WorkTask::OPEN_STATUSES), $seen['work_control'] ?? null)->count()
                : 0,
            'sellers_pending' => $user?->is_platform_admin && Schema::hasTable('seller_payment_claims')
                ? $this->afterWatermark(
                    SellerPaymentClaim::whereIn('status', [SellerPaymentClaim::PENDING_PAYMENT, SellerPaymentClaim::PENDING_REVIEW]),
                    $seen['sellers_pending'] ?? null,
                )->count()
                : 0,
        ];
    }

    /** Mark a watermarked key as seen "now" for this user; returns fresh counts. */
    public function markSeen(User $user, string $key): array
    {
        $maxId = match ($key) {
            'orders_list', 'delivery_ready' => Schema::hasTable('orders') ? (int) Order::max('id') : 0,
            'purchases_toreceive' => Schema::hasTable('purchases') ? (int) Purchase::max('id') : 0,
            'work_control' => Schema::hasTable('work_tasks') ? (int) WorkTask::max('id') : 0,
            'sellers_pending' => Schema::hasTable('seller_payment_claims') ? (int) SellerPaymentClaim::max('id') : 0,
            default => 0,
        };

        SidebarSeen::updateOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            ['business_id' => $user->business_id, 'seen_at' => now(), 'seen_id' => $maxId],
        );

        return $this->countsFor($user);
    }
}
