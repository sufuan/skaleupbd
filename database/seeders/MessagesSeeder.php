<?php

namespace Database\Seeders;

use App\Models\AiActivity;
use App\Models\AiRule;
use App\Models\AiRuleProposal;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Conversation;
use App\Models\KnowledgeEntry;
use App\Models\Product;
use App\Services\RuleBrainService;
use Illuminate\Database\Seeder;

class MessagesSeeder extends Seeder
{
    public function run(): void
    {
        $biz = Business::where('public_id', 'demo-shop')->first();
        if (! $biz) {
            return;
        }
        $products = Product::where('business_id', $biz->id)->get()->keyBy('sku');
        $saree = $products->get('SS-201');
        $tshirt = $products->get('TS-301');
        $wallet = $products->get('LW-401');
        if (! $saree) {
            return;
        }
        if (empty($saree->short_description)) {
            $saree->short_description = 'জর্জেট কাপড়ের শাড়ি। হালকা হাতে ধোয়া যাবে, ছায়ায় শুকাবেন।';
            $saree->save();
        }

        // ---- Rule Brain defaults ----
        $ruleDefs = [
            ['name' => 'Default First Reply', 'match_text' => 'greeting', 'action' => 'answer', 'reply_template' => 'আসসালামু আলাইকুম! কিভাবে সাহায্য করতে পারি? কোন পণ্যটা সম্পর্কে জানতে চান?'],
            ['name' => 'Price answer', 'match_text' => 'price', 'action' => 'answer', 'reply_template' => '{honorific}, {product}-এর দাম {price} টাকা। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা পাঠিয়ে দিন।'],
            ['name' => 'Delivery charge answer', 'match_text' => 'delivery', 'action' => 'answer', 'reply_template' => 'ঢাকার ভিতরে ডেলিভারি চার্জ {delivery_inside} টাকা, ঢাকার বাইরে {delivery_outside} টাকা {honorific}। কোন এলাকায় ডেলিভারি চান?'],
            ['name' => 'Reaction reply', 'match_text' => 'reaction', 'action' => 'answer', 'reply_template' => 'ধন্যবাদ! আর কিছু জানতে চাইলে বলুন।'],
            ['name' => 'Stock answer', 'match_text' => 'stock', 'action' => 'answer', 'reply_template' => '{honorific}, {product} এখন স্টকে আছে ({stock}টা)। অর্ডার করতে চাইলে ঠিকানাটা দিন।'],
            ['name' => 'Price negotiation (ladder)', 'match_text' => 'bargain', 'action' => 'draft', 'reply_template' => null],
            ['name' => 'Order intake', 'match_text' => 'order_intent', 'action' => 'draft', 'reply_template' => null],
            ['name' => 'Spam hide', 'match_text' => 'spam', 'action' => 'hide', 'reply_template' => null],
            ['name' => 'Angry customer handover', 'match_text' => 'angry', 'action' => 'handover', 'reply_template' => null],
        ];
        $rules = [];
        foreach ($ruleDefs as $def) {
            $rules[$def['match_text']] = AiRule::updateOrCreate(
                ['business_id' => $biz->id, 'name' => $def['name']],
                ['trigger_type' => 'intent', 'match_text' => $def['match_text'], 'action' => $def['action'], 'reply_template' => $def['reply_template'], 'active' => true],
            );
        }

        // ---- Knowledge ----
        foreach ([
            ['title' => 'Delivery & Return Policy', 'body' => 'ঢাকার ভিতরে 24–48 ঘণ্টা, বাইরে 3–5 দিন। ত্রুটিপূর্ণ পণ্য 7 দিনে বদলি।'],
            ['title' => 'Payment Info', 'body' => 'Cash on Delivery, bKash 01712-445566'],
            ['title' => 'Tone & Banned Words', 'body' => "ভদ্র, সংক্ষিপ্ত বাংলা। 'সস্তা' শব্দটা ব্যবহার করা যাবে না।"],
        ] as $k) {
            KnowledgeEntry::updateOrCreate(['business_id' => $biz->id, 'title' => $k['title']], ['body' => $k['body']]);
        }

        $brain = app(RuleBrainService::class);
        $mk = function (array $attrs) use ($biz) {
            return Conversation::updateOrCreate(
                ['business_id' => $biz->id, 'customer_name' => $attrs['customer_name'], 'channel' => $attrs['channel']],
                $attrs,
            );
        };

        // ---- Rahim (Messenger) — price, then bargaining; the draft is made by the real engine ----
        $rahim = $mk(['customer_name' => 'Rahim Uddin', 'channel' => 'facebook', 'customer_phone' => '01712-445566', 'customer_address' => 'Mirpur 10, Dhaka', 'product_id' => $saree->id, 'status' => 'open']);
        if (! ChatMessage::where('conversation_id', $rahim->id)->exists()) {
            $brain->handleIncoming($rahim, 'আপু, এই শাড়িটার দাম কত?');
            // Seeded shop starts Draft only: the price answer above lands as a
            // draft; mark the first one sent so the thread reads naturally.
            ChatMessage::where('conversation_id', $rahim->id)->where('sender', 'ai')->oldest('id')->first()?->update(['status' => 'sent']);
            $brain->handleIncoming($rahim->fresh(), 'দামটা একটু কমানো যাবে না?');
            $rahim->update(['unread_count' => 0]);
        }

        // ---- Sharmin (Instagram) — delivery auto answers, then a no-rule question ----
        $sharmin = $mk(['customer_name' => 'Sharmin Akter', 'channel' => 'instagram', 'customer_address' => 'Sonadanga, Khulna', 'product_id' => $saree->id, 'status' => 'open']);
        if (! ChatMessage::where('conversation_id', $sharmin->id)->exists()) {
            ChatMessage::create(['business_id' => $biz->id, 'conversation_id' => $sharmin->id, 'direction' => 'in', 'sender' => 'customer', 'body' => 'Delivery charge koto?', 'status' => 'sent']);
            ChatMessage::create(['business_id' => $biz->id, 'conversation_id' => $sharmin->id, 'direction' => 'out', 'sender' => 'ai', 'body' => 'ঢাকার ভিতরে ৳60, ঢাকার বাইরে ৳120 আপু। কোন এলাকায় ডেলিভারি চান?', 'status' => 'sent', 'ai_rule_id' => $rules['delivery']->id]);
            ChatMessage::create(['business_id' => $biz->id, 'conversation_id' => $sharmin->id, 'direction' => 'out', 'sender' => 'ai', 'body' => 'জি আপু, খুলনায় পাঠানো যাবে — delivery charge ৳120 পড়বে।', 'status' => 'sent', 'ai_rule_id' => $rules['delivery']->id]);
            AiActivity::create(['business_id' => $biz->id, 'action' => 'auto_sent', 'detail' => 'Sharmin Akter — Delivery charge answer', 'ai_rule_id' => $rules['delivery']->id, 'conversation_id' => $sharmin->id]);
            $brain->handleIncoming($sharmin, 'Apu, saree tar kapor ta ki? Hate dhoa jabe?');
            $sharmin->update(['unread_count' => 0, 'last_message_at' => now()]);
        }

        // ---- Karim (WhatsApp) — order intake summary draft, made by the engine ----
        $karim = $mk(['customer_name' => '+880 1712-345678', 'channel' => 'whatsapp', 'customer_phone' => '+880 1712-345678', 'customer_address' => 'Uttara Sector 11, Dhaka', 'product_id' => $saree->id, 'status' => 'open']);
        if (! ChatMessage::where('conversation_id', $karim->id)->exists()) {
            $brain->handleIncoming($karim, 'Order confirm korlam. Name: Karim Sheikh, Address: Uttara Sector 11, Dhaka. Product: Silk Saree 1');
            $karim->update(['customer_name' => '+880 1712-345678', 'unread_count' => 0]);
        }

        // ---- Comments ----
        $commentDefs = [
            ['channel' => 'facebook', 'post_label' => 'Silk Saree — post', 'author_name' => 'Nusrat J.', 'body' => 'দাম কত আপু?', 'product_id' => $saree->id],
            ['channel' => 'instagram', 'post_label' => 'Silk Saree — post', 'author_name' => 'Riya M.', 'body' => 'Price please', 'product_id' => $saree->id],
            ['channel' => 'facebook', 'post_label' => 'T-Shirt Pack — post', 'author_name' => 'Tanvir H.', 'body' => 'Dhaka te delivery hobe? Charge koto?', 'product_id' => $tshirt?->id],
            ['channel' => 'facebook', 'post_label' => 'T-Shirt Pack — post', 'author_name' => 'Rahim Vai', 'body' => 'সস্তায় follower কিনুন! আমার page-এ message করুন', 'product_id' => $tshirt?->id],
            ['channel' => 'instagram', 'post_label' => 'Leather Wallet — post', 'author_name' => 'Mim Chowdhury', 'body' => 'Khub sundor collection!', 'product_id' => $wallet?->id],
        ];
        foreach ($commentDefs as $def) {
            $comment = Comment::updateOrCreate(
                ['business_id' => $biz->id, 'author_name' => $def['author_name'], 'body' => $def['body']],
                $def,
            );
            $comment->refresh(); // DB defaults (status) are not on the fresh model instance
            if ($comment->author_name === 'Nusrat J.' && $comment->status === 'awaiting') {
                CommentReply::create(['business_id' => $biz->id, 'comment_id' => $comment->id, 'kind' => 'public', 'sender' => 'ai', 'body' => 'আপু, এটার দাম ৳3,400। বিস্তারিত আপনার inbox-এ পাঠিয়ে দিয়েছি।', 'status' => 'sent', 'ai_rule_id' => $rules['price']->id]);
                $comment->update(['status' => 'private_sent', 'private_reply_body' => 'আপু, Silk Saree-এর দাম ৳3,400। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা এখানে পাঠিয়ে দিন।', 'private_reply_at' => now(), 'ai_rule_id' => $rules['price']->id]);
            }
            if ($comment->author_name === 'Riya M.' && ! CommentReply::where('comment_id', $comment->id)->exists()) {
                $brain->handleComment($comment);
            }
            if ($comment->author_name === 'Rahim Vai' && $comment->status === 'awaiting') {
                $brain->handleComment($comment);
            }
        }

        // ---- Learning-loop proposals waiting for a human ----
        foreach ([
            ['title' => 'New rule: Cash on Delivery প্রশ্ন', 'detail' => 'গত সপ্তাহে ১৪টা conversation-এ staff হাতে লিখেছে "Cash on Delivery হবে আপু" — এই উত্তরটা rule হিসেবে যোগ করার প্রস্তাব।', 'rule_data' => ['new_rule' => ['name' => 'Cash on Delivery answer', 'trigger_type' => 'keyword', 'match_text' => 'cash on delivery,ক্যাশ অন ডেলিভারি,cod hobe,সিওডি', 'action' => 'answer', 'reply_template' => 'জি {honorific}, Cash on Delivery হবে। অর্ডার করতে নাম, ঠিকানা আর ফোন নম্বরটা দিন।']]],
            ['title' => 'Rule বদল: Delivery charge answer', 'detail' => 'Staff ৩ বার draft edit করে "ঢাকার বাইরে ৳120" এর সাথে "২–৩ দিন লাগবে" যোগ করেছে — template-এ যোগ করার প্রস্তাব।', 'rule_data' => ['edit_rule_match' => 'delivery', 'append_template' => ' পৌঁছাতে ২–৩ দিন লাগবে।']],
        ] as $p) {
            AiRuleProposal::updateOrCreate(['business_id' => $biz->id, 'title' => $p['title']], ['detail' => $p['detail'], 'status' => 'pending', 'rule_data' => $p['rule_data']]);
        }
    }
}
