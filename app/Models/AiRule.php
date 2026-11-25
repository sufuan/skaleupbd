<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRule extends Model
{
    protected $guarded = [];

    /**
     * THE MASTER BRAIN RULE LIBRARY — the permanent, canonical source of
     * the Rule Brain's default rules, grouped by category below.
     *
     * This catalog is the single source every install path draws from:
     * the seeder (MessagesSeeder, so a fresh `migrate:fresh --seed`
     * restores the full library), the Business-created provisioning
     * hook, the backfill migrations for existing shops, and the brain's
     * lazy safety net in matchRule. Nothing else may define defaults.
     *
     * ORDER MATTERS: defs are installed in the order written here and
     * matchRule scans keyword rules in install order, so the groups run
     * from the most urgent cases (legal threats, payments, cancels,
     * complaints) down to the everyday ones, with the core intent rules
     * and the spam rules last. Cases that would be shadowed by a coarse
     * intent (a delivery-charge negotiation contains the delivery and
     * bargain words) are wired as their own intents in
     * RuleBrainService::INTENTS, which classifies them BEFORE the
     * coarse intent — those defs carry trigger_type 'intent' with the
     * fine-grained intent name as match_text.
     *
     * The library is meant to GROW, case by case, as real customer
     * conversations teach new cases. To add a case: add ONE def to the
     * right category here, with a test — every shop that lacks it picks
     * it up through ensureDefaults, and existing rows are never touched.
     *
     * @return array<string, array<int, array{name: string, match_text: string, trigger_type: string, action: string, reply_template: ?string}>>
     */
    public static function catalog(): array
    {
        return [
            // ---- Protection & handovers: money disputes, threats, cancels,
            // complaints. A human owns these; the AI acknowledges and hands
            // over, it never decides them. ----
            'Protection & handovers' => [
                ['name' => 'Legal threat or scam accusation', 'match_text' => 'legal_threat', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => null],
                ['name' => 'Payment proof sent', 'match_text' => 'payment_proof', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, আপনার পেমেন্টের তথ্য পেয়েছি। যাচাই করে আমাদের প্রতিনিধি আপনাকে নিশ্চিত করে জানাবেন।'],
                ['name' => 'Payment problem', 'match_text' => 'payment_problem', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, বিষয়টি গুরুত্ব দিয়ে দেখা হচ্ছে। একজন প্রতিনিধি আপনার সাথে যোগাযোগ করবেন।'],
                ['name' => 'Cancel order', 'match_text' => 'cancel_order', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, আপনার বাতিলের অনুরোধ পেয়েছি। অর্ডারটি দেখে আমাদের প্রতিনিধি আপনাকে নিশ্চিত করে জানাবেন।'],
                ['name' => 'Refund request', 'match_text' => 'রিফান্ড, টাকা ফেরত চাই, টাকা ফেরত দিন, টাকা ফেরত, মানি ব্যাক, refund, money back, taka ferot, taka ferot din', 'trigger_type' => 'keyword', 'action' => 'handover', 'reply_template' => '{honorific}, আপনার অনুরোধ পেয়েছি। আমাদের প্রতিনিধি বিষয়টি দেখে আপনার সাথে কথা বলবেন।'],
                ['name' => 'Damaged or defective product', 'match_text' => 'ভাঙা, ভেঙে গেছে, নষ্ট, ছেঁড়া, ফাটা, দাগ, ড্যামেজ, ডিফেক্ট, কাজ করছে না, চলছে না, vanga, bhenge geche, noshto, chera, damage, damaged, broken, defect, defective, not working', 'trigger_type' => 'keyword', 'action' => 'handover', 'reply_template' => '{honorific}, অসুবিধার জন্য দুঃখিত। ছবি বা ভিডিওসহ বিষয়টি জানালে আমাদের প্রতিনিধি দ্রুত দেখবেন।'],
                ['name' => 'Wrong product received', 'match_text' => 'ভুল প্রোডাক্ট, ভুল পণ্য, অন্য জিনিস, অন্য পণ্য পাঠিয়েছেন, ভুল সাইজ, ভুল কালার, vul product, vul jinis, onno jinis, onno product, wrong item, wrong product, wrong size, wrong colour, not what i ordered', 'trigger_type' => 'keyword', 'action' => 'handover', 'reply_template' => '{honorific}, অসুবিধার জন্য দুঃখিত। ছবিসহ বিষয়টি জানালে আমাদের প্রতিনিধি দ্রুত দেখবেন।'],
                ['name' => 'Return or exchange request', 'match_text' => 'রিটার্ন করব, রিটার্ন দিব, ফেরত দিব, ফেরত দিতে চাই, এক্সচেঞ্জ করতে চাই, বদলে দিতে হবে, পছন্দ হয়নি, সাইজ হয়নি, ঠিক হয়নি, return korbo, return dibo, ferot dibo, exchange korte chai, size hoyni, pochondo hoyni, want to return, want exchange', 'trigger_type' => 'keyword', 'action' => 'handover', 'reply_template' => '{honorific}, আপনার অনুরোধ পেয়েছি। আমাদের প্রতিনিধি বিষয়টি দেখে আপনার সাথে যোগাযোগ করবেন।'],
                ['name' => 'Late delivery complaint', 'match_text' => 'late_delivery', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, দেরির জন্য আমরা দুঃখিত। আপনার পার্সেলের অবস্থা দেখে আমাদের প্রতিনিধি দ্রুত জানাবেন।'],
                ['name' => 'Angry customer', 'match_text' => 'angry', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => null],
                // Profanity is a stronger case than anger (batch 15):
                // the brain stays FULLY silent — no holding line, no
                // acknowledgement — and only the human flag goes up.
                // The 'abuse' intent carries profanity only, so an
                // angry complaint without it keeps the angry path.
                ['name' => 'Abusive language', 'match_text' => 'abuse', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => null],
                ['name' => 'Wholesale or bulk order', 'match_text' => 'wholesale', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, পাইকারি বা বেশি পরিমাণে অর্ডারের জন্য আমাদের প্রতিনিধি আপনার সাথে কথা বলবেন। কোন পণ্য কতগুলো লাগবে জানাবেন।'],
                ['name' => 'Wants a human or a call', 'match_text' => 'wants_human', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, একজন প্রতিনিধি শীঘ্রই আপনার সাথে যোগাযোগ করবেন। আপনার সুবিধার সময় জানাবেন।'],
                ['name' => 'Are you a bot', 'match_text' => 'are_you_bot', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, আপনার প্রশ্নটি দেখে একজন প্রতিনিধি সরাসরি উত্তর দেবেন।'],
            ],
            // ---- Changes & price questions: specific asks that contain the
            // coarse price/bargain/delivery words and must not fall into
            // those flows. ----
            'Changes & price questions' => [
                ['name' => 'Change order or address', 'match_text' => 'change_order', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, কী পরিবর্তন করতে চান নতুন তথ্যসহ জানিয়ে দিন। আমরা অর্ডারটি দেখে আপডেট করে নিশ্চিত করব।'],
                ['name' => 'Open-box check before paying', 'match_text' => 'open_box', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, ডেলিভারির সময় পণ্য দেখে নেওয়ার বিষয়টি দোকান ও কুরিয়ারের নিয়ম অনুযায়ী নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'Competitor price claim', 'match_text' => 'competitor_price', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, আপনার কথা বুঝতে পারছি। {product}-এর বর্তমান দাম {price} টাকা। এ বিষয়ে আমরা দেখে জানাচ্ছি।'],
                ['name' => 'Delivery charge negotiation', 'match_text' => 'delivery_negotiation', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, ডেলিভারি চার্জ ঢাকার ভেতরে {delivery_inside} টাকা আর ঢাকার বাইরে {delivery_outside} টাকা। চার্জ নিয়ে আপনার অনুরোধ আমরা দেখে জানাচ্ছি।'],
                ['name' => 'Advertised price mismatch', 'match_text' => 'price_mismatch', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, {product}-এর বর্তমান দাম {price} টাকা। আগের পোস্টের দামের সাথে অমিল থাকলে আমরা বিষয়টি দেখে আপনাকে নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'Restock date question', 'match_text' => 'restock', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, {product} আবার কবে পাওয়া যাবে সেটা নিশ্চিত করে জানাচ্ছি।'],
            ],
            // ---- Delivery & payment: how it arrives, how it is paid for. ----
            'Delivery & payment' => [
                ['name' => 'Delivery time question', 'match_text' => 'delivery_time', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, ডেলিভারিতে কত সময় লাগবে সেটা আপনার এলাকা অনুযায়ী নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'Coverage area or courier choice', 'match_text' => 'delivery_coverage', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, আপনার এলাকায় বা পছন্দের কুরিয়ারে ডেলিভারি সম্ভব কি না ঠিকানা অনুযায়ী নিশ্চিত করে জানাচ্ছি। জেলা আর থানা বা উপজেলার নামটা জানাবেন।'],
                // A missed delivery the customer wants sent again
                // (batch 15): only a human can rebook it with the
                // courier, so it hands over with an apology — it used
                // to fall into the delivery-CHARGE answer, which does
                // not answer it at all.
                ['name' => 'Redelivery request', 'match_text' => 'redelivery', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, দুঃখিত, ডেলিভারিটা আবার পাঠানোর বিষয়টি আমাদের প্রতিনিধি কুরিয়ারের সাথে কথা বলে জানাচ্ছেন।'],
                ['name' => 'Urgent delivery', 'match_text' => 'আজকেই লাগবে, আজকের মধ্যে, কালকের মধ্যে, জরুরি, খুব দরকার, এক্সপ্রেস, সেম ডে, ajkei lagbe, kalker moddhe, jaruri, urgent, same day, express, asap', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, দ্রুত ডেলিভারির বিষয়টি আপনার ঠিকানা দেখে নিশ্চিত করে জানাচ্ছি। কোনো সময়ের নিশ্চয়তা এখনই দিতে পারছি না।'],
                ['name' => 'Advance or partial payment', 'match_text' => 'advance_payment', 'trigger_type' => 'intent', 'action' => 'draft', 'reply_template' => '{honorific}, অগ্রিম বা আংশিক পেমেন্টের বিষয়টি দোকানের নিয়ম অনুযায়ী নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'Payment number request', 'match_text' => 'বিকাশ নম্বর, বিকাশ নাম্বার, বিকাশে পাঠাব, নগদ নম্বর, রকেট নম্বর, কোথায় টাকা পাঠাব, পেমেন্ট নম্বর, bkash number, bikash number, nagad number, rocket number, send money number, payment number, kothay taka pathabo', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, পেমেন্টের সঠিক নম্বরটি দোকানের নিশ্চিত তথ্য থেকে পাঠানো হবে। অন্য কোনো নম্বরে টাকা পাঠাবেন না।'],
                // HOW to pay, as opposed to the number (batch 15):
                // the platform holds no per-shop payment-methods
                // declaration to answer from, so the honest reply is
                // the deferral — a method or a number is never
                // invented here.
                ['name' => 'Payment methods question', 'match_text' => 'কিভাবে পেমেন্ট, পেমেন্ট কিভাবে, পেমেন্টের নিয়ম, পেমেন্ট মেথড, বিকাশ আছে, বিকাশে দেওয়া যাবে, বিকাশে দিতে পারব, নগদে দেওয়া যাবে, kivabe payment, payment kivabe, payment method, how to pay, bkash ache, payment option, payment options', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, কোন কোন মাধ্যমে পেমেন্ট করা যাবে, সেটা দোকানের নিশ্চিত তথ্য দেখে জানাচ্ছি।'],
                // Handover, not draft (brain fix batch 2): the line
                // promises the shop will check its policy and confirm —
                // as a plain draft/sent reply nobody was ever flagged to
                // do that check, so the promise was empty. As a handover
                // the acknowledgement still goes out AND needs_human
                // stays up until a person actually answers. There is no
                // shop-level COD-availability setting to answer from.
                ['name' => 'Cash on delivery question', 'match_text' => 'cod', 'trigger_type' => 'intent', 'action' => 'handover', 'reply_template' => '{honorific}, পেমেন্ট পদ্ধতি নিয়ে আপনার প্রশ্নটি দোকানের নিয়ম দেখে নিশ্চিত করে জানাচ্ছি।'],
            ],
            // ---- Everyday questions: the small talk and product questions a
            // shop hears all day. ----
            'Everyday questions' => [
                ['name' => 'Small talk', 'match_text' => 'কেমন আছেন, কেমন আছো, কেমন আছেন ভাই, kemon achen, kemon acho, kmn achen, how are you, hru', 'trigger_type' => 'keyword', 'action' => 'answer', 'reply_template' => '{honorific}, আমরা ভালো আছি, ধন্যবাদ। আপনাকে কীভাবে সাহায্য করতে পারি?'],
                ['name' => 'Thanks and positive feedback', 'match_text' => 'ধন্যবাদ, থ্যাংক ইউ, থ্যাংকস, ভালো লেগেছে, পেয়ে গেছি, পণ্য পেয়েছি, dhonnobad, dhonnyobad, thanks, thank you, thx, tnx, valo legeche, pelam valo, received, got it, great product', 'trigger_type' => 'keyword', 'action' => 'answer', 'reply_template' => '{honorific}, আপনাকেও ধন্যবাদ। আবার প্রয়োজনে জানাবেন।'],
                ['name' => 'Will decide later', 'match_text' => 'পরে জানাব, পরে জানাবো, ভেবে দেখি, ভেবে জানাব, পরে নিব, পরে অর্ডার করব, pore janabo, pore nibo, pore order korbo, vebe dekhi, later, i will think, will let you know', 'trigger_type' => 'keyword', 'action' => 'answer', 'reply_template' => 'ঠিক আছে {honorific}, কোনো সমস্যা নেই। প্রয়োজন হলে জানাবেন।'],
                // A polite decline OUTSIDE an order flow (batch 15):
                // a calm close, no order pressure. Inside a flow the
                // same words are a cancellation and batch 7's flow
                // handling owns them before any rule is consulted.
                ['name' => 'Polite decline', 'match_text' => 'লাগবে না, নিব না, নেব না, লাগবেনা, চাই না, দরকার নেই, এখন নিব না, এখন লাগবে না, lagbe na, nibo na, nebo na, chai na, dorkar nai, ekhon nibo na, not needed, no need', 'trigger_type' => 'keyword', 'action' => 'answer', 'reply_template' => 'ঠিক আছে {honorific}, কোনো সমস্যা নেই। পরে প্রয়োজন হলে জানাবেন।'],
                ['name' => 'Courier did not call', 'match_text' => 'কুরিয়ার ফোন করেনি, কুরিয়ার কল করেনি, কুরিয়ার ফোন দেয়নি, কুরিয়ার কল দেয়নি, ডেলিভারি ম্যান ফোন করেনি, রাইডার ফোন করেনি, courier phone koreni, courier call koreni, courier phone kore ni, courier did not call, no call from courier', 'trigger_type' => 'keyword', 'action' => 'handover', 'reply_template' => '{honorific}, দুঃখিত, বিষয়টি আমরা কুরিয়ারের সাথে কথা বলে দেখছি। আমাদের প্রতিনিধি আপনাকে জানাবেন।'],
                // There is no invoice-sending mechanism in chat
                // (batch 15): a human produces it, so the request
                // hands over — the AI never promises to send one.
                ['name' => 'Invoice or memo request', 'match_text' => 'ইনভয়েস, মেমো, রিসিপ্ট, চালান কপি, invoice, memo, receipt, bill copy', 'trigger_type' => 'keyword', 'action' => 'handover', 'reply_template' => '{honorific}, ইনভয়েস বা মেমোর বিষয়টি আমাদের প্রতিনিধি আপনার অর্ডার দেখে জানাচ্ছেন।'],
                ['name' => 'Gift wrap or note', 'match_text' => 'গিফট র‍্যাপ, গিফট প্যাক, গিফট নোট, উপহারের প্যাকেট, gift wrap, gift pack, gift note, gift wrapping, wrapping', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, গিফট র‍্যাপ বা নোটের সুযোগ আছে কিনা, দোকান থেকে নিশ্চিত করে জানাচ্ছি।'],
                // No chat reservation mechanism exists (batch 15):
                // a human confirms from live stock; the AI promises
                // no hold.
                ['name' => 'Reserve or hold request', 'match_text' => 'রেখে দেন, রেখে দিন, হোল্ড করেন, হোল্ড করে রাখেন, রিজার্ভ করে, বুক করে রাখেন, rekhe den, rekhe din, hold kore rakhen, reserve kore, book kore rakhen, keep it for me, hold it for me', 'trigger_type' => 'keyword', 'action' => 'handover', 'reply_template' => '{honorific}, পণ্যটি রেখে দেওয়ার বিষয়টি আমাদের প্রতিনিধি স্টক দেখে নিশ্চিত করে জানাবেন।'],
                // Usage and ingredients (batch 15): the Product
                // details family's honest deferral — facts come from
                // the shop's own information or not at all; the AI
                // never invents ingredients or instructions.
                ['name' => 'Usage or ingredients question', 'match_text' => 'উপাদান, ব্যবহারের নিয়ম, কিভাবে ব্যবহার, ব্যবহার করবো, কিভাবে লাগাব, কিভাবে খাব, ingredients, ingredient, how to use, usage niyom', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, {product} কীভাবে ব্যবহার করতে হয় বা এতে কী উপাদান আছে, সেটা দোকানের তথ্য দেখে নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'Product details question', 'match_text' => 'কী দিয়ে তৈরি, কি কাপড়, কাপড়ের মান, ম্যাটেরিয়াল, ওজন কত, সাইজ কত ইঞ্চি, মাপ কত, ফিচার, স্পেসিফিকেশন, বিস্তারিত বলুন, ডিটেইল, details dao, material ki, kapor ki, fabric, weight, spec, specification, features, details', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, {product} সম্পর্কে আপনি যা জানতে চাইছেন সেটা দোকানের তথ্য দেখে নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'More photos or video request', 'match_text' => 'আরও ছবি, আরো ছবি, আর ছবি, আসল ছবি, রিয়েল ছবি, ভিডিও দিন, ভিডিও আছে, ছবি দেন, ছবি পাঠান, pic dao, picture den, real pic, more photos, more pictures, video, vid dao', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, আপনার অনুরোধ পেয়েছি। {product}-এর আরও ছবি বা ভিডিওর বিষয়টি দেখে জানাচ্ছি।'],
                ['name' => 'Authenticity or quality guarantee', 'match_text' => 'অরিজিনাল, আসল তো, নকল না তো, ফেক না তো, কোয়ালিটি ভালো তো, মান কেমন, গ্যারান্টি, গ্যারেন্টি, ওয়ারেন্টি, original, orginal, fake na to, quality kemon, guarantee, warranty, authentic, genuine', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, {product}-এর মান ও নিশ্চয়তা সম্পর্কে সঠিক তথ্য দোকানের কাছ থেকে নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'Recommendation or comparison', 'match_text' => 'কোনটা ভালো, কোনটা নিব, কোনটা নিলে ভালো, কোনটা সুন্দর, সাজেস্ট, সাজেশন, পরামর্শ দিন, উপহার দিতে, গিফট দিতে, konta valo, konta nibo, suggest, suggestion, recommend, which one is better, gift', 'trigger_type' => 'keyword', 'action' => 'answer', 'reply_template' => '{honorific}, আপনার জন্য সবচেয়ে ভালো পণ্যটি বেছে দিতে একটু জানাবেন, কাকে বা কী কাজে ব্যবহার করবেন?'],
                ['name' => 'Ad or post reference', 'match_text' => 'বিজ্ঞাপনে দেখেছি, এডে দেখেছি, পোস্টে দেখেছি, পোস্ট দেখে, ফেসবুকে দেখেছি, ad dekhe, ad e dekhlam, post dekhe, post e dekhlam, saw your ad, saw your post', 'trigger_type' => 'keyword', 'action' => 'answer', 'reply_template' => '{honorific}, ধন্যবাদ। কোন পণ্যটির কথা বলছেন একটু জানাবেন? নাম বা ছবি পাঠালে দ্রুত জানাতে পারব।'],
                ['name' => 'Size advice', 'match_text' => 'কোন সাইজ হবে, কত সাইজ নিব, কোন সাইজ নিব, আমার হাইট, আমার ওজন, সাইজ চার্ট, সাইজ গাইড, ঢিলা হবে, টাইট হবে, kon size hobe, koto size nibo, size chart, size guide, which size, fit hobe, amar height, amar weight', 'trigger_type' => 'keyword', 'action' => 'answer', 'reply_template' => '{honorific}, সঠিক সাইজ বেছে দিতে আপনার উচ্চতা আর ওজনটা জানাবেন। সেই অনুযায়ী সাহায্য করব।'],
                ['name' => 'Return policy question', 'match_text' => 'রিটার্ন পলিসি, রিটার্ন করা যাবে, ফেরত নেন, ফেরত নেওয়া যায়, এক্সচেঞ্জ করা যাবে, এক্সচেঞ্জ হয়, বদলে দেন কি, পছন্দ না হলে, return policy, return hoy, return kora jabe, exchange hoy, exchange policy, if i dont like', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, রিটার্ন ও এক্সচেঞ্জের নিয়ম দোকানের লিখিত নিয়ম থেকে জানিয়ে দিচ্ছি।'],
                ['name' => 'Offer or coupon enquiry', 'match_text' => 'অফার আছে, কোনো অফার, কি অফার, অফার চলছে, কম্বো, কম্বো অফার, কুপন, কুপন কোড, প্রোমো কোড, ভাউচার, ছাড় আছে, ডিসকাউন্ট কোড, ঈদ অফার, offer ache, offer cholche, kono offer, combo, combo offer, coupon, coupon code, promo code, voucher, discount code, any offer', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, চলমান অফার বা কুপন সম্পর্কে সঠিক তথ্য দেখে জানাচ্ছি। কোন পণ্যের জন্য জানতে চান?'],
                ['name' => 'Shop location or pickup', 'match_text' => 'দোকান কোথায়, দোকানের ঠিকানা, আপনাদের ঠিকানা, শোরুম কোথায়, অফিস কোথায়, নিজে এসে নিব, সরাসরি এসে, দোকানে এসে দেখে, dokan kothay, dokaner thikana, apnader thikana, showroom kothay, office kothay, shop address, store location, where is your shop, pickup, collect myself', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, দোকানের ঠিকানা আর সরাসরি আসার নিয়ম নিশ্চিত করে জানাচ্ছি।'],
                ['name' => 'Opening hours', 'match_text' => 'কখন খোলা, কয়টা পর্যন্ত খোলা, খোলা থাকে, অফিস টাইম, খোলার সময়, kokhon khola, koyta porjonto, opening time, opening hours, office time, shop time, are you open', 'trigger_type' => 'keyword', 'action' => 'draft', 'reply_template' => '{honorific}, আমাদের খোলা থাকার সময় নিশ্চিত করে জানাচ্ছি।'],
            ],
            // ---- Core intents: the selling conversation itself. bargain and
            // order_intent are BEHAVIOUR rules (null template): the code runs
            // the ladder and the order-collection flow. ----
            'Core intents' => [
                ['name' => 'Price answer', 'match_text' => 'price', 'trigger_type' => 'intent', 'action' => 'answer', 'reply_template' => '{honorific}, {product}-এর দাম {price} টাকা। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা পাঠিয়ে দিন।'],
                ['name' => 'Bargain', 'match_text' => 'bargain', 'trigger_type' => 'intent', 'action' => 'answer', 'reply_template' => null],
                ['name' => 'Stock answer', 'match_text' => 'stock', 'trigger_type' => 'intent', 'action' => 'answer', 'reply_template' => '{honorific}, {product} এখন আছে: {stock}। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বর পাঠিয়ে দিন।'],
                ['name' => 'Order intent', 'match_text' => 'order_intent', 'trigger_type' => 'intent', 'action' => 'answer', 'reply_template' => null],
                ['name' => 'Delivery charge answer', 'match_text' => 'delivery', 'trigger_type' => 'intent', 'action' => 'answer', 'reply_template' => '{honorific}, ডেলিভারি চার্জ ঢাকার ভেতরে {delivery_inside} টাকা আর ঢাকার বাইরে {delivery_outside} টাকা।'],
                ['name' => 'Greeting', 'match_text' => 'greeting', 'trigger_type' => 'intent', 'action' => 'answer', 'reply_template' => '{honorific}, স্বাগতম। কোন পণ্যটি সম্পর্কে জানতে চান?'],
                ['name' => 'Reaction', 'match_text' => 'reaction', 'trigger_type' => 'intent', 'action' => 'answer', 'reply_template' => '{honorific}, আর কিছু জানার থাকলে নির্দ্বিধায় বলুন।'],
            ],
            // ---- Spam: hidden, never answered. ----
            'Spam' => [
                ['name' => 'Spam', 'match_text' => 'spam', 'trigger_type' => 'intent', 'action' => 'hide', 'reply_template' => null],
                ['name' => 'Unsolicited services and scams', 'match_text' => 'ঘরে বসে আয়, বাড়ি বসে ইনকাম, লোন, ঋণ, বিনিয়োগ, ক্রিপ্টো, ফলোয়ার বাড়ান, লাইক বাড়ান, পেজ প্রমোট, মার্কেটিং সার্ভিস, এসইও, লিংকে ক্লিক, ওটিপি পাঠান, ghore boshe income, loan, investment, crypto, followers, boost your page, page promotion, marketing service, seo service, click this link, send otp, work from home', 'trigger_type' => 'keyword', 'action' => 'hide', 'reply_template' => null],
            ],
        ];
    }

    /**
     * Every default rule in the master library, flattened across its
     * categories — the list ensureDefaults installs, in install order.
     *
     * @return array<int, array{name: string, match_text: string, trigger_type: string, action: string, reply_template: ?string}>
     */
    public static function defaults(): array
    {
        return array_merge([], ...array_values(self::catalog()));
    }

    /**
     * Install any missing default rules for a shop. Keyed on
     * (business_id, trigger_type, match_text) with firstOrCreate:
     * a row that already exists is NEVER modified, reactivated or
     * duplicated — a seller's edited template or a rule they deliberately
     * switched off stays exactly as they left it.
     */
    public static function ensureDefaults(int $businessId): void
    {
        foreach (self::defaults() as $def) {
            self::firstOrCreate(
                ['business_id' => $businessId, 'trigger_type' => $def['trigger_type'] ?? 'intent', 'match_text' => $def['match_text']],
                ['name' => $def['name'], 'action' => $def['action'], 'reply_template' => $def['reply_template'], 'active' => true],
            );
        }
    }
}
