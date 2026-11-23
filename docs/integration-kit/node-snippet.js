/**
 * SkaleUp BD - Website Integration Kit: Node.js Snippet
 * ======================================================
 * Place in: docs/integration-kit/node-snippet.js
 *
 * For sellers whose website runs Node.js (Express, Next.js, etc.)
 * This code goes on YOUR OWN site. Requires Node.js 18+.
 *
 * Install dependency: npm install node-fetch  (or use built-in fetch in Node 18+)
 */

'use strict';

// =============================================================================
// CONFIGURATION — put these in your .env file
// =============================================================================

const SKALEUP_ENDPOINT       = process.env.SKALEUP_ENDPOINT       || 'https://scalenowbd.com/api/external/v1/orders';
const SKALEUP_API_KEY        = process.env.SKALEUP_API_KEY        || '';
const SKALEUP_BUSINESS_ID    = process.env.SKALEUP_BUSINESS_ID    || '';
const SKALEUP_WEBHOOK_SECRET = process.env.SKALEUP_WEBHOOK_SECRET || '';


// =============================================================================
// SEND ORDER TO SKALEUP
// Call this from a background queue / worker on your side.
// NEVER call directly inside the checkout HTTP handler.
// =============================================================================

/**
 * @param {object} order - Your order data (see format below).
 * @param {string} idempotencyKey - Use your order ID to prevent duplicates.
 * @returns {Promise<{success: boolean, skaleupOrderId: string|null, error: string|null}>}
 */
async function sendOrderToSkaleUp(order, idempotencyKey) {
    const body = JSON.stringify(order);

    let res;
    try {
        res = await fetch(SKALEUP_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type':       'application/json',
                'Accept':             'application/json',
                'x-api-key':          SKALEUP_API_KEY,
                'x-business-id':      SKALEUP_BUSINESS_ID,
                'x-idempotency-key':  idempotencyKey,
            },
            body,
            signal: AbortSignal.timeout(15_000),
        });
    } catch (err) {
        return { success: false, skaleupOrderId: null, error: String(err) };
    }

    let json;
    try { json = await res.json(); } catch { json = {}; }

    if (res.ok && json.success) {
        return { success: true, skaleupOrderId: json.data?.orderId ?? null, error: null };
    }

    return {
        success:        false,
        skaleupOrderId: null,
        error:          json.message ?? `HTTP ${res.status}`,
    };
}


// =============================================================================
// EXAMPLE ORDER FORMAT
// =============================================================================

/*
const result = await sendOrderToSkaleUp({
    externalRef:     '1234',
    customerName:    'Rahim Uddin',
    customerNumber:  '01712345678',
    customerAddress: '12 Mirpur Road, Dhaka',
    district:        'Dhaka',
    deliveryCharge:  60,
    discount:        0,
    advance:         0,
    note:            '',
    webStatus:       'pending',
    products: [
        { productId: '55', name: 'Cotton T-Shirt', quantity: 2, price: 450 },
    ],
}, '1234'); // idempotency key = your order ID

if (result.success) {
    console.log('Sent. SkaleUp order ID:', result.skaleupOrderId);
} else {
    console.error('SkaleUp error:', result.error);
    // Retry via your queue/worker.
}
*/


// =============================================================================
// RECEIVE STATUS UPDATES FROM SKALEUP (Express route example)
// Register the URL in SkaleUp Dashboard > Website Integration > Webhook URL
// =============================================================================

const crypto = require('crypto');

/**
 * Express middleware / route handler.
 *
 * app.post('/webhooks/skaleup', express.raw({ type: 'application/json' }), skaleupWebhook);
 */
function skaleupWebhook(req, res) {
    const timestamp = req.headers['x-skale-timestamp'] || '';
    const signature = req.headers['x-skale-signature'] || '';
    const body      = req.body; // Buffer — requires express.raw()

    // Reject stale payloads (older than 5 minutes).
    if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) {
        return res.status(400).send('Timestamp expired');
    }

    const expected = crypto
        .createHmac('sha256', SKALEUP_WEBHOOK_SECRET)
        .update(timestamp + '.' + body)
        .digest('hex');

    if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))) {
        return res.status(401).send('Bad signature');
    }

    let payload;
    try { payload = JSON.parse(body); } catch { return res.status(400).send('Bad JSON'); }

    const event = payload.event || '';

    switch (event) {
        case 'order.shipped':
            // payload.externalRef = your order ID
            // payload.courier     = courier name
            // payload.tracking    = tracking number
            break;
        case 'order.delivered':
            // Mark your order as delivered.
            break;
        case 'order.cancelled':
            break;
        case 'stock.updated':
            // payload.productId = your product ID
            // payload.available = new available stock quantity
            break;
        default:
            break;
    }

    res.status(200).send('OK');
}

module.exports = { sendOrderToSkaleUp, skaleupWebhook };
