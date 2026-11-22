<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationStage;
use App\Models\Order;
use App\Models\StageEvent;
use Illuminate\Support\Facades\DB;

/**
 * M12 Sales Growth — the stage layer.
 *
 * One row per conversation in conversation_stage, one append-only row
 * per stage change in stage_events. The rules that keep the Marketing
 * page honest live here and nowhere else:
 *  - stage_reached NEVER decreases;
 *  - advance() is idempotent per dedupe key (a replayed message/order
 *    id can never double-count);
 *  - stage evidence comes only from real signals: the FINAL intent of
 *    a customer message (RuleBrain keywords first, the AI classifier
 *    fallback second — the source is recorded on the event), contact
 *    details captured by the guided collection flow, and real orders
 *    matched by phone.
 */
class StageService
{
    public const METRIC_VERSION = 1;

    /** Intents that mean "showed interest" (stage 1). */
    public const INTEREST_INTENTS = ['price', 'stock', 'delivery', 'bargain'];

    public function stageRow(Conversation $conversation): ConversationStage
    {
        return ConversationStage::firstOrCreate(
            ['conversation_id' => $conversation->id],
            ['business_id' => (int) $conversation->business_id, 'stage_reached' => 0, 'stage_current' => 0]
        );
    }

    /**
     * A customer message arrived: stamp the conversation's inbound
     * times + 24h window and make sure a stage row exists at stage 0.
     */
    public function noteInbound(Conversation $conversation, ChatMessage $message): void
    {
        $at = $message->created_at ?? now();
        $dirty = false;
        if (! $conversation->first_inbound_at) {
            $conversation->first_inbound_at = $at;
            $dirty = true;
        }
        $conversation->last_inbound_at = $at;
        $conversation->window_expires_at = $at->copy()->addHours(24);
        $dirty = true;
        if ($dirty) {
            $conversation->save();
        }
        $this->stageRow($conversation);
    }

    /** Any outbound message (staff reply or AI send): stamp it. */
    public function noteOutbound(Conversation $conversation): void
    {
        $conversation->last_outbound_at = now();
        $conversation->save();
    }

    /**
     * Move a conversation to a higher stage. Never downgrades; a repeat
     * call with the same dedupe key is a no-op. Returns the stage row.
     */
    public function advance(Conversation $conversation, int $to, string $reason, array $evidence = [], string $source = 'rule', ?string $dedupeKey = null): ConversationStage
    {
        // Checker layer: no concrete evidence, no stage change. An AI
        // output (or anything else) without a message id / intent /
        // order id behind it changes nothing.
        if ($evidence === []) {
            return $this->stageRow($conversation);
        }

        return DB::transaction(function () use ($conversation, $to, $reason, $evidence, $source, $dedupeKey) {
            $row = $this->stageRow($conversation);

            if ($dedupeKey !== null && StageEvent::where('conversation_id', $conversation->id)->where('dedupe_key', $dedupeKey)->exists()) {
                return $row; // replayed event — already counted exactly once
            }

            $from = (int) $row->stage_reached;
            if ($to > $from) {
                $row->stage_reached = $to;
                if (! $row->closed_reason) {
                    $row->stage_current = $to;
                }
                $col = 'reached_at_'.$to;
                if ($to >= 1 && $to <= 4 && ! $row->{$col}) {
                    $row->{$col} = now();
                }
                $row->save();
                StageEvent::create([
                    'business_id' => (int) $conversation->business_id,
                    'conversation_id' => $conversation->id,
                    'from_stage' => $from,
                    'to_stage' => $to,
                    'reason_code' => $reason,
                    'evidence' => $evidence ?: null,
                    'source' => $source,
                    'dedupe_key' => $dedupeKey,
                ]);
            } elseif ($dedupeKey !== null) {
                // Not an advance (already at/past this stage) — still
                // record the evidence once so replays stay idempotent
                // and "কেন এই ধাপে?" can show it.
                StageEvent::create([
                    'business_id' => (int) $conversation->business_id,
                    'conversation_id' => $conversation->id,
                    'from_stage' => $from,
                    'to_stage' => $from,
                    'reason_code' => $reason,
                    'evidence' => $evidence ?: null,
                    'source' => $source,
                    'dedupe_key' => $dedupeKey,
                ]);
            }

            return $row;
        });
    }

    /**
     * Rule vs AI disagreement on the same message. The rule result
     * wins (rule-first), but the disagreement is logged — these are
     * the highest-value cases for the weekly 90% audit sample.
     */
    public function noteDisagreement(Conversation $conversation, ChatMessage $message, string $ruleIntent, string $aiIntent): void
    {
        $this->noteSignal($conversation, 'intent_disagreement', [
            'message_id' => $message->id, 'rule_intent' => $ruleIntent, 'ai_intent' => $aiIntent,
        ], 'ai', 'msg:'.$message->id.':disagreement');
    }

    /** Record evidence that is not a stage change (e.g. an angry message). */
    public function noteSignal(Conversation $conversation, string $reason, array $evidence = [], string $source = 'rule', ?string $dedupeKey = null): void
    {
        if ($evidence === []) {
            return; // checker layer: no evidence, no record
        }
        $row = $this->stageRow($conversation);
        if ($dedupeKey !== null && StageEvent::where('conversation_id', $conversation->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return;
        }
        StageEvent::create([
            'business_id' => (int) $conversation->business_id,
            'conversation_id' => $conversation->id,
            'from_stage' => (int) $row->stage_reached,
            'to_stage' => (int) $row->stage_reached,
            'reason_code' => $reason,
            'evidence' => $evidence ?: null,
            'source' => $source,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    /** Close a conversation out of the stuck segments (ordered / spam). */
    public function close(Conversation $conversation, string $reason, array $evidence = [], string $source = 'system', ?string $dedupeKey = null): void
    {
        $row = $this->stageRow($conversation);
        if ($row->closed_reason === $reason) {
            return;
        }
        if ($dedupeKey !== null && StageEvent::where('conversation_id', $conversation->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return;
        }
        $row->closed_reason = $reason;
        $row->closed_at = now();
        if ($reason === 'ordered') {
            $row->stage_current = max((int) $row->stage_current, 3);
        }
        $row->save();
        StageEvent::create([
            'business_id' => (int) $conversation->business_id,
            'conversation_id' => $conversation->id,
            'from_stage' => (int) $row->stage_reached,
            'to_stage' => (int) $row->stage_reached,
            'reason_code' => 'closed_'.$reason,
            'evidence' => $evidence ?: null,
            'source' => $source,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    /**
     * The FINAL intent decided for one customer message — RuleBrain
     * keyword result first, the AI classifier's fallback intent when
     * the keywords found nothing. $source is 'rule' or 'ai' and is
     * stored on the event, so the audit can tell the two apart.
     */
    public function noteIntent(Conversation $conversation, ChatMessage $message, ?string $intent, string $source = 'rule'): void
    {
        if ($intent === null || $intent === '') {
            return;
        }
        $key = 'msg:'.$message->id.':intent';
        $evidence = ['message_id' => $message->id, 'intent' => $intent];

        if ($intent === 'spam') {
            $this->close($conversation, 'spam', $evidence, $source, $key);

            return;
        }
        if ($intent === 'angry') {
            $this->noteSignal($conversation, 'angry', $evidence, $source, $key);

            return;
        }
        if ($intent === 'order_intent') {
            $this->advance($conversation, 2, 'order_intent', $evidence, $source, $key);

            return;
        }
        if (in_array($intent, self::INTEREST_INTENTS, true)) {
            $this->advance($conversation, 1, 'intent_'.$intent, $evidence, $source, $key);
        }
    }

    /** The guided collection flow captured a phone and/or an address. */
    public function noteContactShared(Conversation $conversation): void
    {
        if (! $conversation->customer_phone && ! $conversation->customer_address) {
            return;
        }
        if (! $conversation->contact_shared) {
            $conversation->contact_shared = true;
            $conversation->save();
        }
        $this->advance($conversation, 2, 'contact_shared', [
            'has_phone' => (bool) $conversation->customer_phone,
            'has_address' => (bool) $conversation->customer_address,
        ], 'rule', 'contact:'.($conversation->customer_phone ?: 'addr'));
    }

    /**
     * A real order entered the system: every open conversation of this
     * business whose customer phone matches the order's phone counts
     * as ordered (stage 3) and closes as ordered.
     */
    public function noteOrderCreated(Order $order): void
    {
        $phone = PhoneNormalizer::normalize((string) ($order->customer_phone ?? $order->customer?->phone ?? ''));
        if ($phone === '') {
            return;
        }
        $convs = Conversation::where('business_id', (int) $order->business_id)
            ->whereNotNull('customer_phone')
            ->get()
            ->filter(fn ($c) => PhoneNormalizer::normalize((string) $c->customer_phone) === $phone)
            // A spam-closed conversation never enters the funnel, so an
            // order must not pull it into "ordered" either — the
            // reconciliation identity depends on the exclusion.
            ->reject(fn ($c) => $this->stageRow($c)->closed_reason === 'spam');
        foreach ($convs as $conv) {
            $this->advance($conv, 3, 'order_created', ['order_id' => $order->id], 'order', 'order:'.$order->id);
            $this->close($conv, 'ordered', ['order_id' => $order->id], 'order', 'order:'.$order->id.':close');
        }
    }
}
