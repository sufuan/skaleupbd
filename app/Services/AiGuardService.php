<?php

namespace App\Services;

use App\Models\KnowledgeEntry;

/**
 * The guard layer between anything the AI phrases and the customer.
 *
 * Two jobs:
 *  1. Figures — money can be written in many forms (৳1,250, ১২৫০ টাকা,
 *     "550 taka", "পাঁচশো টাকা", 1.5k, ranges). extractFigures() finds
 *     them all, normalised, so the pre-send money check cannot be
 *     bypassed by changing the script or the wording.
 *  2. Claims — the talker may only repeat claims the shop itself makes.
 *     Anything new it adds (availability, delivery promises, guarantees,
 *     free delivery, phone numbers, links) is checked against the truth:
 *     live stock, the shop's Knowledge, and the shop's own contact data.
 */
class AiGuardService
{
    private const BN_DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];

    private const BN_WORDS = [
        'শূন্য' => 0, 'এক' => 1, 'দুই' => 2, 'তিন' => 3, 'চার' => 4, 'পাঁচ' => 5,
        'ছয়' => 6, 'সাত' => 7, 'আট' => 8, 'নয়' => 9, 'দশ' => 10,
        'এগারো' => 11, 'বারো' => 12, 'তেরো' => 13, 'চৌদ্দ' => 14, 'পনেরো' => 15,
        'ষোলো' => 16, 'সতেরো' => 17, 'আঠারো' => 18, 'উনিশ' => 19, 'বিশ' => 20,
        'ত্রিশ' => 30, 'চল্লিশ' => 40, 'পঞ্চাশ' => 50, 'ষাট' => 60, 'সত্তর' => 70,
        'আশি' => 80, 'নব্বই' => 90,
        'একশো' => 100, 'দুইশো' => 200, 'তিনশো' => 300, 'চারশো' => 400, 'পাঁচশো' => 500,
        'ছয়শো' => 600, 'সাতশো' => 700, 'আটশো' => 800, 'নয়শো' => 900,
        'একশ' => 100, 'দুইশ' => 200, 'তিনশ' => 300, 'চারশ' => 400, 'পাঁচশ' => 500,
    ];

    private const EN_WORDS = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'fifteen' => 15, 'twenty' => 20,
        'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60,
        'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    public function normalizeDigits(string $text): string
    {
        return strtr($text, self::BN_DIGITS);
    }

    /**
     * Every money figure in the text, in any common written form.
     * Bare numbers without a money marker are NOT money (they are counts,
     * sizes, dates) and are deliberately left out — the talker guard
     * handles those by comparing against the draft.
     *
     * @return float[]
     */
    public function extractFigures(string $text): array
    {
        $t = $this->normalizeDigits($text);
        $clean = str_replace(',', '', $t);
        $out = [];

        // ৳550 / ৳ 1,250
        if (preg_match_all('/৳\s*(\d+(?:\.\d+)?)/u', $clean, $m)) {
            foreach ($m[1] as $n) {
                $out[] = (float) $n;
            }
        }
        // 550 টাকা / 550 taka / 550 tk / 550/-
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:টাকা|taka|tk|\/-)/iu', $clean, $m)) {
            foreach ($m[1] as $n) {
                $out[] = (float) $n;
            }
        }
        // টাকা 550 / Tk 550 / taka: 550
        if (preg_match_all('/(?:টাকা|taka|tk)\s*:?\s*(\d+(?:\.\d+)?)/iu', $clean, $m)) {
            foreach ($m[1] as $n) {
                $out[] = (float) $n;
            }
        }
        // Sign-less price right after a price word: দাম ৫৫০ / মাত্র 550 / price: 550
        if (preg_match_all('/(?:দাম|মাত্র|price)\s*:?\s*(\d+(?:\.\d+)?)/iu', $clean, $m)) {
            foreach ($m[1] as $n) {
                $out[] = (float) $n;
            }
        }
        // 1.5k
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*k\b/iu', $clean, $m)) {
            foreach ($m[1] as $n) {
                $out[] = (float) $n * 1000;
            }
        }
        // Ranges with a money marker: 500-600 টাকা / ৳500-600
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?)\s*(?:টাকা|taka|tk|৳)/iu', $clean, $m)) {
            foreach ($m[1] as $n) {
                $out[] = (float) $n;
            }
            foreach ($m[2] as $n) {
                $out[] = (float) $n;
            }
        }
        if (preg_match_all('/(?:৳|টাকা|taka|tk)\s*:?\s*(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?)/iu', $clean, $m)) {
            foreach ($m[1] as $n) {
                $out[] = (float) $n;
            }
            foreach ($m[2] as $n) {
                $out[] = (float) $n;
            }
        }
        // Word amounts followed by a money marker: পাঁচশো টাকা, five hundred taka
        if (preg_match_all('/([^\s,।]+(?:\s+[^\s,।]+){0,3}?)\s*(?:টাকা|taka|tk)(?![\p{L}\p{N}])/iu', $t, $m)) {
            foreach ($m[1] as $phrase) {
                $v = $this->parseWordAmount($phrase);
                if ($v !== null) {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }

    /**
     * Clean one token for word lookup. NOTE: PHP's trim() treats its second
     * argument as a BYTE list — passing a multibyte char like । corrupts
     * Bengali tokens (their first byte gets eaten). Strip punctuation with
     * a regex instead, and drop a trailing counter suffix (বিশটা → বিশ).
     */
    private function wordToken(string $tok): string
    {
        // Strip only known punctuation — a character-class strip is unsafe
        // for Bengali, whose vowel signs are combining marks, not letters.
        $tok = preg_replace('/^[ \t,।.!?;:"\'()]+|[ \t,।.!?;:"\'()]+$/u', '', $tok) ?? $tok;
        foreach (['খানা', 'টা', 'টি'] as $sfx) {
            if (mb_strlen($tok) > mb_strlen($sfx) && str_ends_with($tok, $sfx)) {
                $base = mb_substr($tok, 0, mb_strlen($tok) - mb_strlen($sfx));
                if (isset(self::BN_WORDS[$base]) || isset(self::EN_WORDS[$base])) {
                    return $base;
                }
            }
        }

        return $tok;
    }

    /** Parse a short number-word phrase ("পাঁচশো", "দুই হাজার", "five hundred"). */
    public function parseWordAmount(string $phrase): ?float
    {
        $tokens = preg_split('/\s+/u', trim(mb_strtolower($phrase))) ?: [];
        $total = 0.0;
        $current = 0.0;
        $found = false;
        foreach ($tokens as $tok) {
            $tok = $this->wordToken($tok);
            if (isset(self::BN_WORDS[$tok])) {
                $v = self::BN_WORDS[$tok];
                $current += $v;
                $found = true;
            } elseif (isset(self::EN_WORDS[$tok])) {
                $current += self::EN_WORDS[$tok];
                $found = true;
            } elseif ($tok === 'শত' || $tok === 'শো' || $tok === 'hundred') {
                $current = max(1.0, $current) * 100;
                $found = true;
            } elseif ($tok === 'হাজার' || $tok === 'thousand') {
                $total += max(1.0, $current) * 1000;
                $current = 0.0;
                $found = true;
            } elseif ($tok === 'লাখ' || $tok === 'lac' || $tok === 'lakh') {
                $total += max(1.0, $current) * 100000;
                $current = 0.0;
                $found = true;
            }
        }

        return $found ? $total + $current : null;
    }

    /** Any number at all in the text (digits in either script, or a word amount). Used by the talker guard. */
    public function hasAnyNumber(string $text): bool
    {
        if (preg_match('/[0-9০-৯]/u', $text)) {
            return true;
        }
        foreach (preg_split('/\s+/u', mb_strtolower($text)) ?: [] as $tok) {
            $tok = $this->wordToken($tok);
            if (isset(self::BN_WORDS[$tok]) || isset(self::EN_WORDS[$tok])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Claims in $text that were not in the brain's $draft and are not true
     * for this shop. Returns a list of violation labels; empty = clean.
     *
     * $ctx: in_stock (bool), free_delivery_ok (bool), shop_phone (?string)
     */
    public function claimViolations(int $businessId, string $draft, string $text, array $ctx = []): array
    {
        if (trim($text) === trim($draft)) {
            return [];
        }
        $violations = [];
        $hay = mb_strtolower($text);
        $draftHay = mb_strtolower($draft);
        $knowledge = null;
        $inKnowledge = function (string $needle) use ($businessId, &$knowledge) {
            if ($knowledge === null) {
                $knowledge = KnowledgeEntry::where('business_id', $businessId)
                    ->get(['title', 'body'])
                    ->map(fn ($k) => mb_strtolower($k->title . ' ' . $k->body))
                    ->implode(' ');
            }

            return $needle !== '' && str_contains($knowledge, $needle);
        };

        // Availability: never claim stock the shop does not have. The honest
        // zero form ("স্টকে 0টা আছে") is allowed — it states the truth.
        if (($ctx['in_stock'] ?? true) === false) {
            $claimsAvailable = preg_match('/(স্টকে আছে|স্টক আছে|in stock|available)/iu', $text)
                && ! preg_match('/(0|০)\s*টা?\s*আছে/iu', $text)
                && ! str_contains($hay, 'নেই');
            if ($claimsAvailable) {
                $violations[] = 'availability';
            }
        }

        // Promises the shop must have made itself (in Knowledge) first.
        $promises = [
            'কালকেই পাবেন' => 'কালকে', 'কাল পাবেন' => 'কাল', 'আজকেই পাবেন' => 'আজকে',
            '২৪ ঘণ্টা' => 'ঘণ্টা', '24 ঘণ্টা' => 'ঘণ্টা', 'একদিনেই' => 'একদিনে',
            'গ্যারান্টি' => 'গ্যারান্টি', 'guarantee' => 'guarantee',
            'অরিজিনাল' => 'অরিজিনাল', 'original product' => 'original',
        ];
        foreach ($promises as $phrase => $knowledgeKey) {
            if (str_contains($hay, $phrase) && ! str_contains($draftHay, $phrase) && ! $inKnowledge($knowledgeKey)) {
                $violations[] = 'promise:' . $phrase;
            }
        }

        // Free delivery: only when the order actually qualifies, or the
        // shop promises it in Knowledge.
        if ((str_contains($hay, 'ফ্রি ডেলিভারি') || str_contains($hay, 'free delivery'))
            && ! str_contains($draftHay, 'ফ্রি ডেলিভারি') && ! str_contains($draftHay, 'free delivery')
            && ! ($ctx['free_delivery_ok'] ?? false) && ! $inKnowledge('ফ্রি ডেলিভারি') && ! $inKnowledge('free delivery')) {
            $violations[] = 'free_delivery';
        }

        // Links the shop never published.
        if (preg_match_all('/https?:\/\/[^\s]+|www\.[^\s]+/iu', $text, $m)) {
            foreach ($m[0] as $url) {
                if (! str_contains($draftHay, mb_strtolower($url)) && ! $inKnowledge(mb_strtolower($url))) {
                    $violations[] = 'url';
                    break;
                }
            }
        }

        // Phone numbers that are not the shop's own.
        $normalised = $this->normalizeDigits($text);
        if (preg_match_all('/(?:\+?880|0)1[3-9]\d{8}/', $normalised, $m)) {
            $shopPhone = preg_replace('/\D+/', '', $this->normalizeDigits((string) ($ctx['shop_phone'] ?? '')));
            $draftDigits = preg_replace('/\D+/', '', $this->normalizeDigits($draft));
            foreach ($m[0] as $phone) {
                $digits = preg_replace('/\D+/', '', $phone);
                if (str_contains($draftDigits, $digits)) {
                    continue; // the brain itself put this number in the draft
                }
                if ($shopPhone !== '' && substr($digits, -10) === substr($shopPhone, -10)) {
                    continue; // the shop's own number
                }
                $violations[] = 'phone';
                break;
            }
        }

        return $violations;
    }
}
