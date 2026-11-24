<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\CommentReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\DhakaTime;

class CommentsController extends Controller
{
    private function biz(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
    }

    private function guard(Request $request, Comment $comment): void
    {
        abort_unless((int) $comment->business_id === $this->biz($request), 403);
    }

    public function index(Request $request): Response
    {
        $biz = $this->biz($request);
        $comments = Comment::where('business_id', $biz)->with(['replies' => fn ($q) => $q->where('status', '!=', 'rejected')->orderBy('id')])
            ->orderByDesc('id')->get();
        [$todayFrom, $todayTo] = DhakaTime::dayBoundsUtc(DhakaTime::today());

        return Inertia::render('Messages/Comments', [
            'shop_name' => app(\App\Services\SettingsService::class)->shopName($biz),
            'comments' => $comments->map(fn ($c) => [
                'id' => $c->id, 'channel' => $c->channel, 'post_label' => $c->post_label,
                'author_name' => $c->author_name, 'body' => $c->body, 'status' => $c->status,
                'liked' => (bool) $c->liked, 'hidden_by_ai' => (bool) $c->hidden_by_ai,
                'private_reply_at' => $c->private_reply_at?->toIso8601String(),
                'private_reply_body' => $c->private_reply_body,
                'rule' => $c->ai_rule_id ? \App\Models\AiRule::find($c->ai_rule_id)?->name : null,
                'time' => $c->created_at?->diffForHumans(null, true),
                'replies' => $c->replies->map(fn ($r) => [
                    'id' => $r->id, 'kind' => $r->kind, 'sender' => $r->sender, 'body' => $r->body, 'status' => $r->status,
                    'rule' => $r->ai_rule_id ? \App\Models\AiRule::find($r->ai_rule_id)?->name : null,
                ])->all(),
            ])->all(),
            'counts' => [
                // "Today" means the Dhaka calendar day (the app's one
                // timezone rule), not every comment ever (Hunt V5).
                'today' => $comments->filter(fn ($c) => $c->created_at && $c->created_at->between($todayFrom, $todayTo))->count(),
                'awaiting' => $comments->where('status', 'awaiting')->count(),
                'ai_handled' => $comments->filter(fn ($c) => $c->replies->where('sender', 'ai')->count() > 0 || $c->hidden_by_ai)->count(),
            ],
            'posts' => $comments->pluck('post_label')->unique()->values()->all(),
        ]);
    }

    public function reply(Request $request, Comment $comment): RedirectResponse
    {
        $this->guard($request, $comment);
        if ($comment->status === 'hidden') {
            return back()->with('error', __('Hidden comment - unhide it first'));
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        CommentReply::create(['business_id' => $comment->business_id, 'comment_id' => $comment->id, 'kind' => 'public', 'sender' => 'staff', 'body' => $data['body'], 'status' => 'sent']);
        [$ok, $err] = app(\App\Services\MetaGraphService::class)->replyToComment($comment, $data['body']);
        $comment->status = 'replied';
        $comment->save();

        return $ok ? back()->with('success', __('Reply sent')) : back()->with('error', __('Saved, but Meta did not accept it: :error', ['error' => $err]));
    }

    public function privateReply(Request $request, Comment $comment): RedirectResponse
    {
        $this->guard($request, $comment);
        if ($comment->private_reply_at) {
            return back()->with('error', __('Private reply already sent - one per comment within 7 days'));
        }
        if ($comment->status === 'hidden') {
            return back()->with('error', __('Hidden comment - unhide it first'));
        }
        if ($comment->created_at && $comment->created_at->lt(now()->subDays(7))) {
            return back()->with('error', __('Private reply window closed (7 days)'));
        }
        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            $product = $comment->product_id ? \App\Models\Product::find($comment->product_id) : null;
            $prefix = app(\App\Services\HonorificService::class)->forName((int) $comment->business_id, $comment->author_name ?? null);
            $prefix = $prefix !== '' ? $prefix . ', ' : '';
            $body = $product
                ? $prefix . $product->name . '-এর দাম ৳' . number_format((float) $product->price) . '। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা এখানে পাঠিয়ে দিন।'
                : $prefix . 'বিস্তারিত এখানে পাঠিয়ে দিলাম। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা পাঠিয়ে দিন।';
        }
        $comment->private_reply_body = $body;
        $comment->private_reply_at = now();
        $comment->status = 'private_sent';
        $comment->save();
        [$ok, $err] = app(\App\Services\MetaGraphService::class)->privateReplyToComment($comment, $body);

        return $ok ? back()->with('success', __('Private reply sent')) : back()->with('error', __('Saved, but Meta did not accept it: :error', ['error' => $err]));
    }

    public function like(Request $request, Comment $comment): RedirectResponse
    {
        $this->guard($request, $comment);
        $comment->liked = !$comment->liked;
        $comment->save();

        return back();
    }

    public function hide(Request $request, Comment $comment): RedirectResponse
    {
        $this->guard($request, $comment);
        if ($comment->status === 'hidden') {
            // Unhide restores the status the comment had before it was
            // hidden (Hunt Y2-15): 'awaiting' here used to erase the
            // fact that a private reply was already sent. Comments
            // hidden before pre_hide_status existed fall back to what
            // the record still proves.
            $comment->status = $comment->pre_hide_status
                ?? ($comment->private_reply_at ? 'private_sent' : ($comment->replies()->where('status', 'sent')->exists() ? 'replied' : 'awaiting'));
            $comment->pre_hide_status = null;
            $comment->hidden_by_ai = false;
            $nowHidden = false;
        } else {
            $comment->pre_hide_status = $comment->status;
            $comment->status = 'hidden';
            $comment->hidden_by_ai = false;
            $nowHidden = true;
        }
        $comment->save();
        app(\App\Services\MetaGraphService::class)->setCommentHidden($comment, $nowHidden);

        return back();
    }

    public function done(Request $request, Comment $comment): RedirectResponse
    {
        $this->guard($request, $comment);
        if ($comment->status === 'hidden') {
            // Done must not erase the only local record that the
            // comment is still hidden on the platform (Hunt Y2-15) -
            // marking it done does not unhide it there.
            return back()->with('error', __('This comment is hidden on the platform - unhide it before marking it done.'));
        }
        $comment->status = 'done';
        $comment->save();

        return back();
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        $this->guard($request, $comment);
        $comment->replies()->delete();
        $comment->delete();

        return back()->with('success', __('Comment deleted'));
    }

    public function approveReply(Request $request, CommentReply $reply): RedirectResponse
    {
        abort_unless((int) $reply->business_id === $this->biz($request), 403);
        if (! in_array($reply->status, ['draft', 'flagged'], true)) {
            // State discipline (Hunt Y2-14): only a pending draft can
            // be approved - re-approving a sent reply would post it
            // to the platform a second time.
            return back()->with('error', __('This reply was already handled - it cannot be approved or rejected again.'));
        }
        $body = trim((string) $request->input('body', ''));
        if ($body !== '' && $body !== $reply->body) {
            \App\Models\AiRuleProposal::create([
                'business_id' => $reply->business_id,
                'title' => 'Staff edit থেকে শেখা - comment reply বদলানো হয়েছে',
                'detail' => 'আগের draft: "' . $reply->body . '" → staff পাঠিয়েছে: "' . $body . '"',
                'rule_data' => ['source_reply_id' => $reply->id, 'edited_body' => $body, 'note_only' => true],
                'status' => 'pending',
            ]);
            $reply->body = $body;
        }
        $comment = $reply->comment;
        [$ok, $err] = [true, null];
        if ($comment) {
            // The Meta result decides, exactly like reply() above
            // (Hunt Y2-14): a failed post is never marked sent.
            $graph = app(\App\Services\MetaGraphService::class);
            [$ok, $err] = $reply->kind === 'private'
                ? $graph->privateReplyToComment($comment, (string) $reply->body)
                : $graph->replyToComment($comment, (string) $reply->body);
        }
        if (! $ok) {
            $reply->save(); // keep any edit; the reply stays a draft

            return back()->with('error', __('Saved, but Meta did not accept it: :error', ['error' => $err]));
        }
        $reply->status = 'sent';
        $reply->save();
        if ($comment) {
            $comment->status = 'replied';
            $comment->save();
        }

        return back()->with('success', __('Reply sent'));
    }

    public function rejectReply(Request $request, CommentReply $reply): RedirectResponse
    {
        abort_unless((int) $reply->business_id === $this->biz($request), 403);
        if (! in_array($reply->status, ['draft', 'flagged'], true)) {
            // Only a pending draft can be rejected (Hunt Y2-14): a
            // sent reply is the only record of what the shop publicly
            // said - it is never deleted from history.
            return back()->with('error', __('This reply was already handled - it cannot be approved or rejected again.'));
        }
        $reply->status = 'rejected';
        $reply->save();

        return back()->with('success', __('Draft rejected'));
    }
}
