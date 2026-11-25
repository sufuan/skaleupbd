<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\MetaGraphService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Meta send-failure diagnostics (live diagnosis 2026-11-25): ten Page
 * sends failed with only Meta's message text stored ("Application
 * does not have permission for this action") — the code, subcode and
 * fbtrace_id that identify WHICH permission is missing were thrown
 * away. A failed send must now persist Meta's full error alongside
 * the message text, on the ChatMessage meta and in the log line,
 * while send_error keeps holding the message text alone.
 */
class MetaGraphErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** A live-channel conversation + one outgoing message, on the legacy global channel config. */
    private function liveMessage(string $channel = 'facebook'): ChatMessage
    {
        $biz = Business::create(['name' => 'Meta Shop', 'public_id' => 'meta-shop-' . uniqid(), 'status' => 'approved']);
        app(SettingsService::class)->set("channels.{$channel}", [
            'token_enc' => MetaGraphService::encrypt('test-token'),
            'account_id' => $channel === 'whatsapp' ? '15551234567' : 'page-123',
        ]);
        $conv = Conversation::create([
            'business_id' => $biz->id,
            'channel' => $channel,
            'customer_name' => 'Test Customer',
            'customer_phone' => '8801712345678',
            'external_id' => $channel === 'whatsapp' ? '8801712345678' : 'psid-123',
            'status' => 'open',
        ]);

        return ChatMessage::create([
            'business_id' => $biz->id,
            'conversation_id' => $conv->id,
            'direction' => 'out',
            'sender' => 'ai',
            'status' => 'sent',
            'body' => 'hello',
        ]);
    }

    public function test_failed_send_persists_full_meta_error_on_message_and_log(): void
    {
        $msg = $this->liveMessage('facebook');
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Application does not have permission for this action',
                    'type' => 'OAuthException',
                    'code' => 200,
                    'error_subcode' => 2018022,
                    'fbtrace_id' => 'AbCdEf123',
                ],
            ], 400),
        ]);
        Log::spy();

        [$ok, $error] = app(MetaGraphService::class)->dispatch($msg);

        $this->assertFalse($ok);
        $this->assertSame('Application does not have permission for this action', $error);

        $meta = $msg->fresh()->meta;
        $this->assertSame('failed', $meta['send_status']);
        // Byte-identical to before: send_error holds the message text alone.
        $this->assertSame('Application does not have permission for this action', $meta['send_error']);
        $this->assertSame(200, $meta['send_error_code']);
        $this->assertSame(2018022, $meta['send_error_subcode']);
        $this->assertSame('OAuthException', $meta['send_error_type']);
        $this->assertSame('AbCdEf123', $meta['send_fbtrace_id']);

        Log::shouldHaveReceived('warning')->with('Meta send failed', Mockery::on(
            fn (array $ctx) => ($ctx['error_code'] ?? null) === 200
                && ($ctx['error_subcode'] ?? null) === 2018022
                && ($ctx['fbtrace_id'] ?? null) === 'AbCdEf123'
        ));
    }

    public function test_failed_send_without_machine_fields_records_message_and_nulls(): void
    {
        $msg = $this->liveMessage('facebook');
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Some failure'],
            ], 400),
        ]);
        Log::spy();

        [$ok, $error] = app(MetaGraphService::class)->dispatch($msg);

        $this->assertFalse($ok);
        $this->assertSame('Some failure', $error);

        $meta = $msg->fresh()->meta;
        $this->assertSame('failed', $meta['send_status']);
        $this->assertSame('Some failure', $meta['send_error']);
        $this->assertArrayHasKey('send_error_code', $meta);
        $this->assertNull($meta['send_error_code']);
        $this->assertNull($meta['send_error_subcode']);
        $this->assertNull($meta['send_error_type']);
        $this->assertNull($meta['send_fbtrace_id']);

        Log::shouldHaveReceived('warning')->with('Meta send failed', Mockery::on(
            fn (array $ctx) => ($ctx['error'] ?? null) === 'Some failure'
                && array_key_exists('error_code', $ctx) && $ctx['error_code'] === null
                && array_key_exists('error_subcode', $ctx) && $ctx['error_subcode'] === null
        ));
    }

    public function test_failed_whatsapp_send_uses_the_same_error_path(): void
    {
        $msg = $this->liveMessage('whatsapp');
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Application does not have permission for this action',
                    'code' => 200,
                    'fbtrace_id' => 'WaTrace99',
                ],
            ], 400),
        ]);

        [$ok] = app(MetaGraphService::class)->dispatch($msg);

        $this->assertFalse($ok);
        $meta = $msg->fresh()->meta;
        $this->assertSame('failed', $meta['send_status']);
        $this->assertSame(200, $meta['send_error_code']);
        $this->assertSame('WaTrace99', $meta['send_fbtrace_id']);
        // Fields Meta did not send stay null.
        $this->assertNull($meta['send_error_subcode']);
        $this->assertNull($meta['send_error_type']);
    }

    public function test_failed_quick_reply_send_persists_full_meta_error(): void
    {
        $msg = $this->liveMessage('facebook');
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Application does not have permission for this action',
                    'type' => 'OAuthException',
                    'code' => 200,
                    'error_subcode' => 2018022,
                    'fbtrace_id' => 'QrTrace77',
                ],
            ], 400),
        ]);
        Log::spy();

        [$ok] = app(MetaGraphService::class)->dispatchQuickReplies($msg, [['title' => 'Yes', 'payload' => 'yes']]);

        $this->assertFalse($ok);
        $meta = $msg->fresh()->meta;
        $this->assertSame('failed', $meta['send_status']);
        $this->assertSame('Application does not have permission for this action', $meta['send_error']);
        $this->assertSame(200, $meta['send_error_code']);
        $this->assertSame(2018022, $meta['send_error_subcode']);
        $this->assertSame('QrTrace77', $meta['send_fbtrace_id']);

        Log::shouldHaveReceived('warning')->with('Meta quick-reply send failed', Mockery::on(
            fn (array $ctx) => ($ctx['error_code'] ?? null) === 200
                && ($ctx['error_subcode'] ?? null) === 2018022
        ));
    }
}
