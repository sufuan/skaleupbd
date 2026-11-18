<?php

namespace App\Support;

/**
 * The ONLY features a package may contain — the features that actually
 * exist in this software (the seller dashboard's modules + real built-in
 * capabilities). When a new feature ships in the software, add it here in
 * the same change; packages pick features from this list only.
 */
class FeatureCatalog
{
    public static function all(): array
    {
        return [
            'dashboard' => 'Dashboard',
            'orders' => 'Orders',
            'delivery' => 'Delivery & Courier Booking',
            'inventory' => 'Inventory & Stock',
            'purchase' => 'Purchase',
            'messages' => 'Messages Inbox (Facebook & WhatsApp)',
            'ai_reply' => 'AI Auto Reply (Rule Brain)',
            'fraud_check' => 'Fraud & Success-Rate Check',
            'customers' => 'Customers',
            'call_center' => 'Call Center',
            'tasks' => 'Task & Follow-up',
            'work_inbox' => 'Work Inbox',
            'ads' => 'Ads',
            'marketing' => 'Marketing (SMS & Automation)',
            'hrm' => 'HRM (Staff & Attendance)',
            'finance' => 'Finance',
            'reports' => 'Reports',
            'support' => 'Support',
            'storefront' => 'Storefront & Landing Pages',
            'multi_staff' => 'Multiple Staff Accounts',
            'api_access' => 'Own Website Connection (API)',
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function label(string $key): string
    {
        return self::all()[$key] ?? $key;
    }

    /** Display labels for stored feature keys (legacy free text passes through). */
    public static function labels(array $features): array
    {
        return array_map(fn ($f) => self::label((string) $f), $features);
    }
}
