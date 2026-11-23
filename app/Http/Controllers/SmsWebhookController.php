<?php

namespace App\Http\Controllers;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Amar SMS delivery reports (DLR). The provider POSTs its message id and
 * the delivery status here; the matching sms_logs row (written at send
 * time) learns whether the SMS actually arrived.
 *
 * The exact callback field names are confirmed from portal.amarsms.com/docs
 * with the account, so they are read defensively from the likely keys
 * (message_id / id, status / delivery_status). The raw payload of a matched
 * callback is kept on the row's provider_response; a callback that matches
 * no send has no row to attach to, so its raw payload goes to the app log
 * for later reconciliation with the portal docs.
 */
class SmsWebhookController extends Controller
{
    public function amarsms(Request $request)
    {
        $messageId = $request->input('message_id') ?? $request->input('id')
            ?? $request->input('msgid') ?? $request->input('messageId');
        $status = $request->input('status') ?? $request->input('delivery_status')
            ?? $request->input('dlr_status') ?? $request->input('state');

        $log = null;
        if ($messageId) {
            $log = SmsLog::where('provider', 'amarsms')
                ->where('provider_message_id', (string) $messageId)->latest('id')->first()
                ?? SmsLog::where('provider_message_id', (string) $messageId)->latest('id')->first();
        }

        if ($log && $status) {
            // Forward-only: this endpoint is unauthenticated (AmarSMS
            // documents no DLR signature scheme in our integration), so a
            // later report must never move a message backwards — a
            // delivered message cannot go back to pending/failed, and a
            // failed one cannot be resurrected. Blocked reports are kept
            // in the app log for reconciliation and still answered 200.
            if ($this->dlrMovesForward($log->delivery_status, (string) $status)) {
                $log->update([
                    'delivery_status' => (string) $status,
                    'provider_response' => json_encode($request->all()),
                    'updated_at' => now(),
                ]);
            } else {
                Log::info('sms dlr backward ignored', [
                    'message_id' => (string) $messageId,
                    'current' => $log->delivery_status,
                    'reported' => (string) $status,
                ]);
            }
        } else {
            Log::info('sms dlr unmatched', ['payload' => $request->all()]);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Rank + class of a delivery status. Terminal states (delivered,
     * failed family) rank above in-flight ones; "undelivered" is tested
     * before "delivered" because it contains it.
     *
     * @return array{0: int, 1: string}
     */
    private function dlrRank(?string $status): array
    {
        $s = strtolower(trim((string) $status));
        if ($s === '') {
            return [0, 'none'];
        }
        if (str_contains($s, 'undeliver') || str_contains($s, 'fail') || str_contains($s, 'reject')
            || str_contains($s, 'expir') || str_contains($s, 'error') || str_contains($s, 'block')) {
            return [2, 'failed'];
        }
        if (str_contains($s, 'deliver')) {
            return [2, 'delivered'];
        }

        return [1, 'inflight'];
    }

    /** A report may apply only if it moves the row forward (or repeats its own terminal state). */
    private function dlrMovesForward(?string $current, string $reported): bool
    {
        [$curRank, $curClass] = $this->dlrRank($current);
        [$newRank, $newClass] = $this->dlrRank($reported);
        if ($newRank > $curRank) {
            return true;
        }

        return $newRank === $curRank && $newClass === $curClass;
    }

    /**
     * Nishchit delivery events (docs: nishchit.tech/docs/webhooks).
     *
     * Every delivery is signed: header `Nishchit-Signature: t=<unix>,v1=<hex>`
     * where v1 is the HMAC-SHA256 of "{t}.{raw body}" keyed with the webhook
     * secret of the shop's own Nishchit account. Verification runs against
     * the RAW body before anything is trusted: a stale timestamp (outside
     * the documented five-minute window) or a bad signature is rejected
     * with 400 and nothing is updated. The message id is read only to find
     * which shop's secret applies — lookup is not trust. Events that match
     * no send (or carry no message id, e.g. balance.low) are logged raw
     * and acknowledged with 200, as the docs prescribe for unknown ids.
     *
     * Status mapping: the event's `delivery_status` is what the network
     * reported; on the primary BD route it stays "unknown" and the message
     * simply ends at status "sent" — so the row records delivery_status
     * when the network reported one, otherwise the message status itself
     * (accepted / sent / failed). A delivered state is never invented.
     */
    public function nishchit(Request $request)
    {
        $raw = (string) $request->getContent();
        $payload = json_decode($raw, true);
        $data = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $messageId = isset($data['message_id']) ? (string) $data['message_id'] : null;

        $log = null;
        if ($messageId) {
            $log = SmsLog::where('provider', 'nishchit')
                ->where('provider_message_id', $messageId)->latest('id')->first();
        }
        if (! $log) {
            Log::info('sms webhook nishchit unmatched', ['payload' => $payload ?? $raw]);

            return response()->json(['status' => 'success']);
        }

        $secret = app(SmsService::class)->webhookSecret((int) $log->business_id);
        if ($secret === null || ! $this->nishchitSignatureValid($request, $raw, $secret)) {
            Log::warning('sms webhook nishchit rejected', [
                'business_id' => $log->business_id,
                'message_id' => $messageId,
                'reason' => $secret === null ? 'no webhook secret saved for the shop' : 'signature check failed',
            ]);

            return response()->json(['status' => 'error', 'error' => 'Signature verification failed'], 400);
        }

        $reported = isset($data['delivery_status']) ? (string) $data['delivery_status'] : null;
        $status = $reported !== null && $reported !== '' && $reported !== 'unknown'
            ? $reported
            : (isset($data['status']) ? (string) $data['status'] : null);

        if ($status) {
            $log->update([
                'delivery_status' => $status,
                'provider_response' => $raw,
                'updated_at' => now(),
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    /** The documented scheme: v1 = HMAC-SHA256(secret, "{t}.{rawBody}"), t within five minutes. */
    private function nishchitSignatureValid(Request $request, string $raw, string $secret): bool
    {
        $header = (string) $request->header('Nishchit-Signature', '');
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $kv = explode('=', trim($piece), 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }
        $t = $parts['t'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if ($t === null || $v1 === null || ! ctype_digit($t)) {
            return false;
        }
        if (abs(time() - (int) $t) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $raw, $secret);

        return hash_equals($expected, $v1);
    }
}
