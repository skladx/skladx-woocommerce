# SkladX for WooCommerce

Connects a WooCommerce shop with the **SkladX** fulfilment warehouse:

- sends orders to SkladX when they reach a chosen status (Action Scheduler queue, idempotent, retries with backoff),
- syncs products (SKU, name, EAN, weight, dimensions, price) and optionally takes stock levels from SkladX,
- receives order status and tracking updates via signed webhooks (HMAC-SHA256) and a polling fallback,
- shows a SkladX box on the order screen (status, number, tracking, last sync, Send/Resend, Refresh, Cancel in SkladX).

Requirements: WordPress 6.4+, WooCommerce 8.2+, PHP 8.1+. Compatible with HPOS (custom order tables) and Cart/Checkout blocks. No runtime Composer dependencies.

[Slovenská verzia nižšie](#slovensky)

---

## Installation

1. Copy the plugin folder to `wp-content/plugins/skladx-woocommerce` (or upload the ZIP in *Plugins → Add New*).
2. Activate **SkladX for WooCommerce**. WooCommerce must be active.
3. Open **WooCommerce → Settings → SkladX**.

## Configuration

| Setting | Notes |
| --- | --- |
| API base URL | Your SkladX URL including `/api/v1`, e.g. `https://app.example/api/v1`. |
| API key | From the SkladX client portal (*Integrations → API keys*). `skx_test_…` = sandbox, `skx_live_…` = production. Required scopes: `orders:read`, `orders:write`, `products:write`, `events:read`, `webhooks:write` (`stock:read`, `webhooks:read` optional). The key is stored in the options table, never printed back into the page and never logged. |
| Test connection | Calls `GET /ping` and shows client, environment and scopes; warns about missing scopes. |
| Webhook | *Register webhook in SkladX* calls `POST /webhooks` and stores the signing secret. To do it manually, copy the endpoint URL (`https://your-shop/wp-json/skladx/v1/webhook`) into the SkladX portal and paste the `whsec_…` secret into *Webhook signing secret*. |
| Send orders in status | Default *Processing*. An order is sent once. |
| Cash on delivery payment methods | Orders paid with these gateways get `cod_cents` = order total. Default `cod`. |
| Shipping methods → carriers | Each shipping method instance (`method_id:instance_id`) maps to a SkladX carrier: `packeta`, `dpd_sk`, `gls_sk`, `sps`, `posta_sk`, `balikovo`. A plain `method_id` key also works (via the `skladx_carrier_map` option). |
| Pickup point meta key | Order or shipping-line meta holding the pickup point id (default `_skladx_pickup_point_id`). Set it to the key your Packeta/pickup widget writes. SkladX validates the point (must exist, be active and accept COD). |
| SkladX status → WooCommerce status | Every SkladX status change becomes an order note; mapped ones also change the WC status. Defaults: `handed_over` → *Shipped (SkladX)* (custom status `wc-skladx-shipped`, counts as paid), `delivered` → *Completed*, `cancelled` → *Cancelled*. |
| Product sync | On product save (and *Sync all products*) → `POST /products` upsert by SKU. Products without SKU are skipped. Saves that do not change the payload (e.g. stock only) are not sent. |
| Stock sync | Off by default. When on, SkladX is the source of truth: `stock.changed` / `product.*` events set WC stock to SkladX `available` for products with *Manage stock*. |
| Debug log | WooCommerce → Status → Logs, source `skladx`. Secrets are masked (`skx_*`, `whsec_*`, `Bearer …`). Errors are always logged. |

### Order payload

`reference` = WC order number, items by SKU (duplicate SKUs merged, `unit_price_cents` incl. tax), shipping address (billing address as fallback), `carrier`, `pickup_point_id`, `cod_cents`, `total_cents`, `currency`, `customer_email`, `customer_phone`, `note` (customer note) and `metadata: {wc_order_id, wc_order_number, site}`.

The `Idempotency-Key` is `wc:<host>:order:<id>`; after a rejected attempt (4xx) a revision suffix `:r<n>` is added so the corrected order can be sent again. Network errors, 429 and 5xx are retried after 1, 5, 15, 60, 180 and 360 minutes; validation errors (422) stop, are written to the order note and the SkladX box, and show an admin notice. If SkladX already has the reference and it belongs to this order (lost response), the plugin links it instead of failing.

Filters: `skladx_order_payload`, `skladx_product_payload`, `skladx_tracking_url`, `skladx_find_order_by_reference` (for sequential order number plugins).

### Webhook

`POST /wp-json/skladx/v1/webhook`

1. Verifies `X-SkladX-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "t.body")>` over the raw body, timestamp tolerance 300 s, constant-time compare. Invalid → `401`.
2. Stores the event in `wp_skladx_events` keyed by `X-SkladX-Event-Id` (atomic dedupe; duplicates answer `200 {"duplicate":true}`).
3. Answers `200` immediately; processing runs in Action Scheduler (`skladx_process_event`).

Handled events: `order.status_changed`, `order.shipped`, `order.delivered`, `order.delivery_failed`, `order.cancelled`, `order.returned`, `shipment.tracking_updated` (status, mapping, notes, tracking), `stock.changed`, `product.created`, `product.updated` (stock, if enabled), `ping`. Stale events (older `status_changed_at`) are ignored. A new tracking number is added as a **customer note** (sends the "Customer note" email) and shown on the order page and in WooCommerce emails. If SkladX sends no tracking URL, the public tracking page of the carrier is used (Packeta, DPD, GLS, Slovenská pošta).

**Polling fallback:** every 10 minutes `GET /events?after=<last evt_…>` (all pages, processed oldest first). Events the webhook missed go through the same inbox and dedupe. *Fetch missed events now* runs it on demand. The first run only remembers the newest event (no backfill). Changing the API key or URL resets the cursor.

### Uninstall

Deleting the plugin removes all `skladx_*` options, the `wp_skladx_events` table and scheduled actions. Order and product meta (`_skladx_*`) is kept as part of the order history.

## Troubleshooting

- **Test connection → `invalid_api_key` / `insufficient_scope`:** issue a new key with the scopes listed above.
- **Orders stay "Queued":** Action Scheduler runs on WP-Cron. Check *WooCommerce → Status → Scheduled Actions* (group `skladx`); on low-traffic sites use a real cron (`wp action-scheduler run`).
- **422 on export:** the message in the SkladX box says which field (unknown SKU — sync products first; invalid pickup point; missing address). Fix it and click *Resend to SkladX*.
- **Webhooks not arriving:** check *Webhooks → deliveries* in the SkladX portal. `401 invalid_signature` = wrong secret (re-register) or server clock off by more than 5 minutes. The shop must be reachable from SkladX over HTTPS with a valid certificate. Missed events are still picked up by polling.
- **Local development with Laravel Herd/Valet (`*.test`):** WordPress does not trust the local CA. Use a mu-plugin that adds the Herd CA (`~/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem`) via the `http_request_args` filter only for `*.test` hosts and only when `WP_ENVIRONMENT_TYPE` is `local`. Never disable SSL verification.

## Development

```bash
composer install      # dev only: PHPUnit
composer test         # unit tests: signature, payload mapping, status mapping, redaction
wp i18n make-pot . languages/skladx-woocommerce.pot --exclude=vendor,tests
msgfmt -o languages/skladx-woocommerce-sk_SK.mo languages/skladx-woocommerce-sk_SK.po
```

Source strings are English; Slovak translation is in `languages/skladx-woocommerce-sk_SK.po`.

---

<a id="slovensky"></a>

## Slovensky

Plugin prepojí WooCommerce so skladom **SkladX**: odosiela objednávky, synchronizuje produkty a zásoby a cez podpísané webhooky prijíma stavy objednávok a sledovanie zásielok.

### Inštalácia

1. Priečinok pluginu nahrajte do `wp-content/plugins/skladx-woocommerce` (alebo ZIP cez *Pluginy → Pridať nový*).
2. Aktivujte **SkladX pre WooCommerce** (WooCommerce musí byť aktívny).
3. Otvorte **WooCommerce → Nastavenia → SkladX**.

### Nastavenie

- **Základná URL API** – adresa SkladX vrátane `/api/v1`.
- **API kľúč** – z klientskeho portálu SkladX (*Integrácie → API kľúče*). `skx_test_…` = sandbox, `skx_live_…` = produkcia. Oprávnenia: `orders:read`, `orders:write`, `products:write`, `events:read`, `webhooks:write`. Kľúč sa nikdy nezobrazí ani nezapíše do logu.
- **Otestovať pripojenie** – `GET /ping`, zobrazí klienta, prostredie a oprávnenia.
- **Webhook** – tlačidlo *Zaregistrovať webhook v SkladX* ho vytvorí cez API a uloží podpisový kľúč. Manuálne: URL `https://vas-obchod/wp-json/skladx/v1/webhook` vložte do portálu SkladX a kľúč `whsec_…` do poľa *Podpisový kľúč webhooku*.
- **Odosielať objednávky v stave** – predvolene *Spracováva sa*; objednávka sa odošle raz.
- **Platobné metódy na dobierku** – suma objednávky sa pošle ako `cod_cents`.
- **Spôsoby dopravy → dopravcovia** – `packeta`, `dpd_sk`, `gls_sk`, `sps`, `posta_sk`, `balikovo`.
- **Meta kľúč výdajného miesta** – meta pole s ID výdajného miesta (predvolene `_skladx_pickup_point_id`); SkladX miesto overí.
- **Stav SkladX → stav WooCommerce** – každá zmena je poznámka k objednávke, namapované stavy zmenia aj stav objednávky. Predvolene: `handed_over` → *Odoslaná (SkladX)*, `delivered` → *Vybavená*, `cancelled` → *Zrušená*.
- **Synchronizácia produktov** – pri uložení produktu a tlačidlom *Synchronizovať všetky produkty* (upsert podľa SKU; EAN z poľa *EAN pre SkladX* alebo GTIN).
- **Synchronizácia zásob** – predvolene vypnutá; po zapnutí je zdrojom pravdy SkladX.
- **Ladiaci log** – WooCommerce → Stav → Logy, zdroj `skladx`, tajné údaje maskované.

### Webhook a záložné načítanie

Webhook overuje HMAC podpis (tolerancia 300 s), deduplikuje podľa `X-SkladX-Event-Id`, odpovie hneď `200` a spracuje udalosť na pozadí. Číslo zásielky sa zákazníkovi pošle ako poznámka (e-mail) a zobrazí sa v detaile objednávky a v e-mailoch. Každých 10 minút plugin načíta `GET /events?after=…`, aby zachytil zmeškané webhooky.

### Riešenie problémov

- **422 pri odoslaní** – v boxe SkladX pri objednávke je presná chyba (neznáme SKU → najprv synchronizujte produkty; neplatné výdajné miesto; chýbajúca adresa). Po oprave kliknite *Odoslať do SkladX znova*.
- **Objednávka visí vo fronte** – skontrolujte *WooCommerce → Stav → Naplánované akcie* (skupina `skladx`), na málo navštevovaných weboch nastavte skutočný cron.
- **Webhooky nechodia** – v portáli SkladX pozrite doručenia webhooku; `401` = zlý kľúč (zaregistrujte znova) alebo posunutý čas servera. Obchod musí byť dostupný cez HTTPS s platným certifikátom; zmeškané udalosti dobehne polling.
- **Lokálny vývoj (Herd, `*.test`)** – pridajte mu-plugin, ktorý pre hostiteľov `*.test` v lokálnom prostredí pridá certifikát Herd CA cez filter `http_request_args`. Overovanie SSL nikdy nevypínajte.

### Odinštalovanie

Zmaže nastavenia `skladx_*`, tabuľku `wp_skladx_events` a naplánované akcie. Meta údaje objednávok (`_skladx_*`) zostávajú.

## License

GPL-2.0-or-later.
