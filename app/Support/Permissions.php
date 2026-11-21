<?php

namespace App\Support;

/**
 * Sidebar module keys and the role presets behind User & Role (M13).
 * A user's explicit permission list (users.permissions) wins over the preset.
 */
class Permissions
{
    public const ALL = [
        'dashboard', 'orders', 'delivery', 'inventory', 'messages', 'purchase',
        'callcenter', 'tasks', 'workinbox', 'ads', 'marketing', 'customers',
        'hrm', 'finance', 'reports', 'settings', 'billing',
    ];

    /** Key => sidebar label, for the User & Role checkbox list. */
    public const LABELS = [
        'dashboard' => 'Dashboard',
        'orders' => 'Orders',
        'delivery' => 'Delivery',
        'inventory' => 'Inventory',
        'messages' => 'Messages',
        'purchase' => 'Purchase',
        'callcenter' => 'Call Center',
        'tasks' => 'Task & Follow-up',
        'workinbox' => 'Work Inbox',
        'ads' => 'Ads',
        'marketing' => 'Marketing',
        'customers' => 'Customers',
        'hrm' => 'HRM',
        'finance' => 'Finance',
        'reports' => 'Reports',
        'settings' => 'Settings',
        'billing' => 'Points & Billing',
    ];

    public static function preset(string $role): array
    {
        return match ($role) {
            'admin' => self::ALL,
            'manager' => ['dashboard', 'orders', 'delivery', 'inventory', 'messages', 'customers', 'workinbox', 'tasks', 'hrm', 'reports'],
            'accountant' => ['dashboard', 'finance', 'reports', 'customers'],
            default => ['dashboard'],
        };
    }

    public static function roles(): array
    {
        return [
            'admin' => 'Admin',
            'manager' => 'Manager',
            'accountant' => 'Accountant',
            'staff' => 'Staff',
        ];
    }
}
