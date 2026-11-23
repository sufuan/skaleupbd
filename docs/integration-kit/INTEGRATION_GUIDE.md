# SkaleUp BD - Website Integration Guide

**For sellers who want to connect their own website to the SkaleUp dashboard.**

---

## What this integration does

When you connect your website to SkaleUp:

- Every order placed on your website appears automatically in your SkaleUp dashboard.
- You can book courier delivery from the dashboard and your customers see tracking on your website.
- Stock quantities stay in step between your website and your SkaleUp inventory.
- When an order is shipped or delivered, your website is updated automatically.

Your website keeps working on its own at all times. SkaleUp never blocks or rejects your orders.

---

## Before you start

You need:

1. A SkaleUp account with at least one point balance.
2. Access to your website's code (or a developer who can copy-paste a snippet).
3. A URL on your website that can receive a POST request (for status updates).

---

## Step 1 - Get your credentials

1. Open the SkaleUp dashboard.
2. Go to **Website Integration** (under the Storefront menu).
3. Click **Create API Key**. Choose the **Orders + Products** scope.
4. Copy the full API key shown. You will only see it once.
5. Copy your **Business ID** shown on the same page.
6. Enter a **Webhook URL** - a URL on your website where SkaleUp will send status updates (see Step 4 below). You can add this later.

---

## Step 2 - Choose your connection type

### Option A - WooCommerce site

If your site runs WooCommerce:

1. In your WordPress admin, go to **WooCommerce > Settings > Advanced > REST API**.
2. Click **Add key**. Set **Permissions** to **Read/Write**. Click **Generate API key**.
3. Copy the **Consumer key** and **Consumer secret**.
4. Back in the SkaleUp dashboard, go to **Website Integration > Connect WooCommerce**.
5. Enter your site URL, consumer key, and consumer secret. Click **Test and connect**.
6. SkaleUp registers webhooks on your site automatically. Orders will start appearing within seconds of being placed.

**You do not need to install any plugin.** SkaleUp uses the WooCommerce REST API that is built in.

### Option B - Any other website (Laravel, PHP, Node.js, custom)

Use the code snippets in this kit:

| File | Use when |
|---|---|
| `laravel-snippet.php` | Your site runs Laravel |
| `plain-php-snippet.php` | Your site runs plain PHP |
| `node-snippet.js` | Your site runs Node.js |

Follow the numbered steps inside the file that matches your site.

---

## Step 3 - Send orders to SkaleUp

**Important:** Always send orders from a background queue/job on your site. Never call the SkaleUp API directly inside the checkout request. A slow or unavailable network should never delay your customer's checkout.

The API endpoint is:

```
POST https://scalenowbd.com/api/external/v1/orders
```

Required headers:

```
x-api-key:         your_full_api_key
x-business-id:     your_business_id
x-idempotency-key: your_order_id
Content-Type:      application/json
```

If you send the same `x-idempotency-key` twice, SkaleUp returns the original order instead of creating a duplicate. Use your order ID as the key.

**Minimum required fields:**

```json
{
  "externalRef":     "1234",
  "customerName":    "Rahim Uddin",
  "customerNumber":  "01712345678",
  "customerAddress": "12 Mirpur Road, Dhaka",
  "district":        "Dhaka",
  "products": [
    {
      "productId": "55",
      "name":      "Cotton T-Shirt",
      "quantity":  2,
      "price":     450
    }
  ]
}
```

**All available fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| externalRef | string | Yes | Your order ID |
| customerName | string | Yes | |
| customerNumber | string | Yes | BD phone, 11 digits (01XXXXXXXXX) |
| customerAddress | string | Yes | |
| district | string | Yes | e.g. "Dhaka", "Chittagong" |
| products | array | Yes | Min 1 item |
| products[].productId | string | Yes* | Your product ID (*or name) |
| products[].name | string | Yes* | Product name (*or productId) |
| products[].quantity | number | Yes | |
| products[].price | number | Yes | Unit price |
| deliveryCharge | number | No | Default 0 |
| discount | number | No | Default 0 |
| advance | number | No | Amount paid in advance |
| note | string | No | Order note |
| webStatus | string | No | Status on your site (for reference) |

**Successful response:**

```json
{
  "success": true,
  "statusCode": 201,
  "requestId": "req_abc123",
  "data": {
    "orderId": "INV-2026-00123",
    "invoice": "INV-2026-00123"
  }
}
```

**If your balance is zero** (Custom/API connection only):

```json
{
  "success": false,
  "statusCode": 503,
  "message": "Connection suspended. Please top up your points to resume."
}
```

Your order is NOT created on SkaleUp's side. Your site is not affected. Retry later (after topping up). Your kit's queued job handles retries automatically with backoff.

---

## Step 4 - Receive status updates from SkaleUp

Create an endpoint on your site (e.g. `https://yoursite.com/webhooks/skaleup`) and enter its URL in the SkaleUp dashboard under **Website Integration > Webhook URL**.

SkaleUp will POST a JSON payload to that URL when an order status changes.

**Verifying the signature** (always do this):

```
X-Skale-Timestamp: 1700000000
X-Skale-Signature: hex_string
```

```
expected = HMAC-SHA256(key=webhook_secret, message=timestamp + "." + raw_body)
```

Reject the request if the timestamp is more than 5 minutes old, or if the signatures do not match.

**Event types:**

| Event | When it fires |
|---|---|
| `order.confirmed` | Order accepted in SkaleUp |
| `order.shipped` | Courier booked, tracking available |
| `order.delivered` | Marked as delivered |
| `order.returned` | Marked as returned |
| `order.cancelled` | Cancelled |
| `stock.updated` | Available stock changed |

**Example payload (order.shipped):**

```json
{
  "event":       "order.shipped",
  "externalRef": "1234",
  "orderId":     "INV-2026-00123",
  "courier":     "Pathao",
  "tracking":    "PTH-99887766",
  "occurredAt":  "2026-11-23T10:00:00Z"
}
```

---

## Step 5 - Sync products (optional)

Sending your product catalogue to SkaleUp lets the dashboard match incoming orders to your inventory automatically, keeping stock in step.

```
POST https://scalenowbd.com/api/external/v1/products
```

Send up to 100 products at a time:

```json
{
  "products": [
    { "external_id": "55", "name": "Cotton T-Shirt", "sku": "SHIRT-M-BLK", "price": 450, "stock": 100 },
    { "external_id": "56", "name": "Denim Jeans",    "sku": "JEAN-32-BLK", "price": 850, "stock": 40  }
  ]
}
```

---

## Step 6 - Check stock from your website (optional)

```
POST https://scalenowbd.com/api/external/v1/stock/check
```

```json
{ "products": ["55", "56"] }
```

---

## What happens when you run out of points

| Feature | At zero points |
|---|---|
| Your website | Keeps selling normally |
| New orders appearing in dashboard | Stopped |
| Tracking shown on your website | Stopped |
| Stock staying in step | Stopped |
| Courier booking | Stopped |

The moment you top up, everything reconnects automatically. For WooCommerce, any orders that came in during the gap are pulled in by the next reconciliation run (within 15 minutes).

---

## Troubleshooting

**Orders are not appearing in the dashboard**
- Check that your API key has the Orders scope.
- Check that your site's queue/cron is running (jobs need to actually run, not just be queued).
- Check your points balance.

**WooCommerce orders stopped appearing**
- Check that your WooCommerce REST API consumer key still has Read/Write permissions.
- SkaleUp checks every 15 minutes and re-registers any broken webhooks automatically.

**Webhook signature verification failing**
- Make sure you are reading the raw request body before any framework parses it (use `express.raw()` in Node, `$request->getContent()` in Laravel, `file_get_contents('php://input')` in plain PHP).
- Check that your webhook secret in your code matches the one in the SkaleUp dashboard exactly.

**Getting HTTP 503 from the orders API**
- Your point balance is zero. Top up at: **SkaleUp Dashboard > Points and Billing**.

---

## Support

Log into your SkaleUp dashboard and open a Work Inbox ticket, or email support listed in the dashboard footer.
