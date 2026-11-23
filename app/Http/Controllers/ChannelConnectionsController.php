<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Services\ChannelConnectionService;
use App\Services\MetaGraphService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ধাপ ৫: a seller connects and manages their OWN messaging channels
 * (Settings → API Connection → Messaging Channels). The business is
 * always resolved from the signed-in user via ShopContext — never
 * from the form — and the routes are owner-only (the settings module
 * key is non-delegable, ধাপ ৪). Tokens are stored encrypted and never
 * sent back to the browser; the page only receives the token-free
 * view from ChannelConnectionService.
 *
 * Instagram is parked (owner's order): the table and routing are
 * channel-generic, but there is no IG connect flow here.
 */
class ChannelConnectionsController extends Controller
{
    public function __construct(
        private readonly ChannelConnectionService $connections,
    ) {}

    private function shopId(Request $request): int
    {
        $biz = ShopContext::id($request);
        abort_if($biz <= 0, 403);

        return $biz;
    }

    /** Create or update the caller's own connection for one channel, then check it live. */
    public function store(Request $request): RedirectResponse
    {
        $biz = $this->shopId($request);
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:facebook,whatsapp'],
            'external_id' => ['required', 'string', 'max:60'],
            'parent_external_id' => ['nullable', 'string', 'max:60'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'token' => ['nullable', 'string', 'max:2000'],
        ]);
        $channel = (string) $data['channel'];
        $externalId = trim((string) $data['external_id']);

        // One Page/number = one shop, platform-wide (the unique index
        // is the backstop; this is the plain-words refusal).
        $existing = ChannelConnection::where('channel', $channel)->where('external_id', $externalId)->first();
        if ($existing && (int) $existing->business_id !== $biz) {
            return back()->with('error', $channel === 'facebook'
                ? 'This Facebook Page is already connected to another shop.'
                : 'This WhatsApp number is already connected to another shop.');
        }
        $conn = $existing;
        if (! $conn) {
            if (empty($data['token'])) {
                return back()->with('error', 'Paste the access token to connect.');
            }
            $conn = new ChannelConnection([
                'business_id' => $biz,
                'channel' => $channel,
                'external_id' => $externalId,
                'connect_method' => 'manual',
            ]);
        }
        if (empty($conn->token_enc) && empty($data['token'])) {
            // A reconnect after disconnect (the token was cleared) or a
            // first save without a token: there is nothing to check.
            return back()->with('error', 'Paste the access token to connect.');
        }
        $conn->parent_external_id = $channel === 'whatsapp'
            ? (trim((string) ($data['parent_external_id'] ?? '')) ?: $conn->parent_external_id)
            : null;
        if (! empty($data['display_name'])) {
            $conn->display_name = trim((string) $data['display_name']);
        }
        if ($channel === 'whatsapp' && ! empty($data['phone_number'])) {
            $meta = (array) ($conn->meta ?? []);
            $meta['display_phone_number'] = trim((string) $data['phone_number']);
            $conn->meta = $meta;
            if (! $conn->display_name) {
                $conn->display_name = trim((string) $data['phone_number']);
            }
        }
        if (! empty($data['token'])) {
            $conn->token_enc = MetaGraphService::encrypt(trim((string) $data['token']));
            $conn->token_type = $channel === 'whatsapp' ? 'system_user' : 'page';
        }
        $conn->connected_by = $request->user()?->id;
        $conn->status = 'connected';
        $conn->last_error = null;
        $conn->save();

        // Save AND check in one step: the seller never meets a separate
        // "check" concept. A failed check keeps the typed ids and puts
        // Meta's own error on the row, where the card shows it.
        $result = $this->connections->check($conn);
        if ($result['ok'] && $channel === 'facebook') {
            $sub = $this->connections->subscribeFacebook($conn);
            if (! $sub['ok']) {
                $conn->forceFill([
                    'status' => 'error',
                    'last_error' => $sub['detail'],
                    'last_checked_at' => now(),
                ])->save();

                return back()->with('error', 'Saved, but the app could not subscribe to this Page: ' . $sub['detail']);
            }
        }
        if (! $result['ok']) {
            return back()->with('error', 'Saved, but Meta check failed: ' . $result['detail']);
        }

        return back()->with('success', $channel === 'facebook' ? 'Facebook Page connected' : 'WhatsApp connected');
    }

    /** "Check now" on the caller's own connection (JSON, like the platform check). */
    public function check(Request $request): JsonResponse
    {
        $biz = $this->shopId($request);
        $channel = (string) $request->input('channel', '');
        abort_unless(in_array($channel, ['facebook', 'whatsapp'], true), 422);
        $conn = ChannelConnection::forBusiness($biz, $channel);
        if (! $conn) {
            return response()->json(['ok' => false, 'detail' => 'Not connected yet']);
        }
        $result = $this->connections->check($conn);

        return response()->json(['ok' => $result['ok'], 'detail' => $result['detail']]);
    }

    /** Disconnect the caller's own connection for one channel (token is cleared). */
    public function disconnect(Request $request): RedirectResponse
    {
        $biz = $this->shopId($request);
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:facebook,whatsapp'],
        ]);
        $conn = ChannelConnection::forBusiness($biz, (string) $data['channel']);
        if (! $conn) {
            return back()->with('error', 'Nothing to disconnect');
        }
        $this->connections->disconnect($conn);

        return back()->with('success', 'Disconnected');
    }
}
