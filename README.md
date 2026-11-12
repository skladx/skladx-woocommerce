# SkladX for WooCommerce

Connects a WooCommerce shop with the **SkladX** fulfilment warehouse:

- sends orders to SkladX when they reach a chosen status (Action Scheduler queue, idempotent, retries with backoff),
- pickup point picker at checkout (classic checkout and Checkout block) for Packeta and other pickup carriers, with points from SkladX,
- sends products to SkladX right after they are saved (SKU, stable `external_id`, name, EAN, weight, dimensions, price, images; queue fallback with retries), handles SKU renames and SKU conflicts, and optionally takes stock levels from SkladX,
- receives order status and tracking updates via signed webhooks (HMAC-SHA256) and a polling fallback,
- shows a SkladX box on the order screen (status, number, tracking with a *Track shipment* button, pickup point, last sync, Send/Resend, Refresh, Cancel in SkladX),
- **receipts** (*WooCommerce → SkladX receipts*): announce goods you send to the warehouse — header, items with batch and expiry, bulk paste, EAN scanning, attachments, label for the parcel, received quantities and differences.

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
| API key | From the SkladX client portal (*Integrations → API keys*). `skx_test_…` = sandbox, `skx_live_…` = production. Required scopes: `orders:read`, `orders:write`, `products:write`, `events:read`, `webhooks:write`; `pickup_points:read` for the pickup point picker, `stock:read` for taking over stock levels, `inbound:read` + `inbound:write` for receipts (`webhooks:read` optional). The key is stored in the options table, never printed back into the page and never logged. |
| Test connection | Calls `GET /ping` and shows client, environment and scopes; warns about missing scopes. |
| Webhook | *Register webhook in SkladX* calls `POST /webhooks` and stores the signing secret. To do it manually, copy the endpoint URL (`https://your-shop/wp-json/skladx/v1/webhook`) into the SkladX portal and paste the `whsec_…` secret into *Webhook signing secret*. |
| Send orders in status | Default *Processing*. An order is sent once. |
| Cash on delivery payment methods | Orders paid with these gateways get `cod_cents` = order total. Default `cod`. |
| Shipping methods → carriers | Each shipping method instance (`method_id:instance_id`) maps to a SkladX carrier: `packeta`, `dpd_sk`, `gls_sk`, `sps`, `posta_sk`, `balikovo`. A plain `method_id` key also works (via the `skladx_carrier_map` option). The *Pickup point* column marks methods that deliver to a pickup point (default: every method mapped to `packeta`, filter `skladx_pickup_carriers`). |
| Pickup point picker | On by default. Adds *Choose pickup point* under a chosen pickup point method at checkout (see below). |
| Pickup point meta key | Order or shipping-line meta holding the pickup point id (default `_skladx_pickup_point_id`). The picker writes here; if another pickup point plugin is used, set the key it writes. SkladX validates the point (must exist, be active and accept COD). |
| Packeta widget API key | Optional. When set, Packeta methods open the official Packeta widget (`widget.packeta.com`) instead of the built-in picker. The chosen point is still verified with SkladX. The key is public (used in the browser). |
| SkladX status → WooCommerce status | Every SkladX status change becomes an order note; mapped ones also change the WC status. Defaults: `handed_over` → *Shipped (SkladX)* (custom status `wc-skladx-shipped`, counts as paid), `delivered` → *Completed*, `cancelled` → *Cancelled*. |
| Product sync | On product save (and *Sync all products*) → `POST /products` upsert by `external_id`, then by SKU — see [Product sync](#product-sync). Saves that do not change the payload (e.g. stock only) are not sent. |
| Products without SKU | On by default. Products and variations without their own SKU are sent as `WC-{id}` (see [Product sync](#product-sync)). When off, they are skipped, marked *No SKU* in the product list and counted under *Tools*. |
| Stock sync | Off by default. When on, SkladX is the source of truth: `stock.changed` / `product.*` events set WC stock to SkladX `available` (on hand − reserved) for products with *Manage stock*. Units of WC orders not yet in SkladX (e.g. bank transfer on hold) stay deducted. Turning it on, or *Take over stock from SkladX now*, pulls all levels via `GET /stock`. Older events never overwrite newer ones (event ids are time-ordered). |
| Debug log | WooCommerce → Status → Logs, source `skladx`. Secrets are masked (`skx_*`, `whsec_*`, `Bearer …`). Errors are always logged. |

### Order payload

`reference` = WC order number, items by SKU with the product's `external_id` (variation id for variations; SkladX looks the product up by `external_id` first, then by SKU; duplicate SKUs merged, `unit_price_cents` incl. tax), shipping address (billing address as fallback), `carrier`, `pickup_point_id`, `cod_cents`, `total_cents`, `currency`, `customer_email`, `customer_phone`, `note` (customer note) and `metadata: {wc_order_id, wc_order_number, site}`.

The `Idempotency-Key` is `wc:<host>:order:<id>`; after a rejected attempt (4xx) a revision suffix `:r<n>` is added so the corrected order can be sent again. Network errors, 429 and 5xx are retried after 1, 5, 15, 60, 180 and 360 minutes; validation errors (422) stop, are written to the order note and the SkladX box, and show an admin notice. If SkladX already has the reference and it belongs to this order (lost response), the plugin links it instead of failing.

Filters: `skladx_order_payload`, `skladx_product_payload`, `skladx_tracking_url`, `skladx_find_order_by_reference` (for sequential order number plugins), `skladx_webhook_url` (public webhook URL behind a proxy).

### Product sync

- **Stable `external_id`.** Every product is sent with `external_id` = `wc:{site}:{id}` — the product id for simple products, the variation id for variations (e.g. `wc:shop.example:57`). `{site}` is the *site key*: the lowercase host of the site address (`home_url()`, `www.` kept) saved once in the `skladx_site_key` option and never recomputed, so moving the shop to another domain keeps the link to SkladX. Uninstalling the plugin keeps this option too. SkladX finds the product by `external_id` first and by SKU second (products synced before 0.4.0 are linked on their next sync).
- **SKU rename.** Changing the SKU of a product SkladX already knows (same `external_id`) renames the SKU in SkladX — no duplicate product.
- **SKU conflict.** If the new SKU already belongs to another SkladX product, SkladX answers `409 sku_conflict`. The plugin stores the error on the product (which SkladX product owns the SKU: name, SKU, id and its link), shows it as an error on the product edit screen and as a *SkladX: error* badge in the product list, and does **not** retry: the same payload is not sent again until the product is saved with a change or you click *Retry sync* in the notice. Validation errors (422) behave the same way.
- **Immediate sync with queue fallback.** Saved products are collected during the request — wp-admin (also a new product published for the first time, or a SKU added later), variations (including *Save changes* in the variations panel), REST API (`woocommerce_rest_insert_product_object`), CSV import and trash/restore (sent as `archived`) — and pushed at the end of the request with a 5 s timeout, once per product. Saving a variable product checks its variations. At most 10 changed products are pushed per request, the rest goes to the Action Scheduler queue (`skladx_sync_product`). Network errors, 429 and 5xx are retried after 1, 5, 15, 60, 180 and 360 minutes. Unchanged payloads are skipped, but a SKU change or a missing `external_id` link always sends.
- **Products without SKU → `WC-{id}`.** SkladX needs a SKU for every product (receiving, barcode labels). A product or variation without its own SKU is sent as `WC-{id}` — the product id, or the variation id for variations (e.g. `WC-57`). The id never changes, so the code is stable. Because the product is linked by `external_id`, adding a real SKU later renames the SKU of the same SkladX product (stock and history stay); if the real SKU already belongs to another SkladX product you get the usual SKU conflict. Order items, stock updates and receipts use the same `WC-{id}`, which is never written into the WooCommerce SKU field. Not used for virtual products, and a variation already synced under its parent's SKU (before 0.4.1) keeps that SKU. Turn it off with *Products without SKU*; filter `skladx_fallback_sku` (must return the same value for a product forever).
- **EAN.** `_skladx_ean` (*EAN for SkladX* field), then the native WooCommerce *GTIN, UPC, EAN or ISBN* field (`global_unique_id`, WooCommerce 9.2+), then the meta keys of common EAN plugins: `_ean`, `_gtin`, `_alg_ean`, `_wpm_gtin_code`, `hwp_product_gtin`, `hwp_var_gtin`, `_ts_gtin`, `_woosea_ean` (filter `skladx_ean_meta_keys`). A variation without any EAN falls back to the parent's `_skladx_ean`. Receipts find products by all of these.
- **Sync all products.** The button under *Tools* (or `wp skladx sync-products`, `--queue` to run it in Action Scheduler) sends every simple product, variation and other stockable type in every status except trash (published, private, draft, pending, scheduled) in batches; variable, grouped and external parents are skipped (variations stand for their parent). Progress and the result (sent / failed / skipped without SKU) and the number of products without SKU are shown under *Tools*. After the update to 0.4.1 the full sync runs once automatically if the shop has products without a SKU.
- **Images.** `image_url` is the absolute URL of the main image (relative URLs are resolved against the site address, `http://` becomes `https://` on HTTPS sites, other schemes are skipped); `images` has up to 10 gallery image URLs without the main image (variations without their own gallery use the parent's).
- Errors stay in the product meta `_skladx_product_error` (plus `_skladx_product_error_code`) until the next successful sync and are also shown as one-time admin notices (except on the product edit screen, which shows them itself).

### Pickup point picker

Works in the classic checkout and in the Checkout block (no build step, no extra libraries).

1. When the chosen shipping method is a pickup point method, *Choose pickup point* opens a dialog: search by city, postcode or name, *Near me* (browser location, 10 km), a list with type, COD/card badges, distance and opening hours, and an OpenStreetMap preview of the highlighted point. When a COD gateway is selected, only points accepting cash on delivery are listed.
2. Points come from the plugin's REST proxy `GET /wp-json/skladx/v1/pickup-points?carrier=packeta&q=…&near=lat,lng&cod=1&cursor=…&country=SK`, which calls SkladX `GET /pickup-points` with the shop's API key (never sent to the browser). Only carriers of pickup point methods are allowed; answers are cached per query for 1 hour (transients), single points for 6 hours, and cache misses are limited to 30 per minute per visitor. Filter: `skladx_pickup_points_query`.
3. The choice is saved via the Store API (`POST /wc/store/v1/cart/extensions`, namespace `skladx-pickup`), verified with SkladX (`GET /pickup-points/{carrier}/{id}`) and kept in the WooCommerce session.
4. Placing the order validates again on the server: a point must be chosen, belong to the carrier, be active, and accept COD when a COD gateway is used. The id is stored in the pickup point meta key; `_skladx_pickup_point_carrier`, `_skladx_pickup_point_name`, `_skladx_pickup_point_address` and `_skladx_pickup_point` (full point) are stored for display on the order screen, the thank-you/My account page and in emails. The id is sent to SkladX as `pickup_point_id`.

### Receipts (WooCommerce → SkladX receipts)

Announce stock you are sending to the warehouse (a stock receipt / advice). SkladX is the source of truth — nothing is stored in WordPress except a small status cache for notices. Capability `manage_woocommerce`; every call goes through `admin-ajax.php` / `admin-post.php` with a nonce, the API key never reaches the browser.

- **List:** receipts from `GET /inbound-receipts` with status badges (Draft, Awaiting receipt, In transit, Arrived at warehouse, Receiving, Received, Cancelled), expected date, carrier and tracking link, announced/received pieces with difference, status filter, cursor pagination (*Next page*). If SkladX cannot be reached, the last known statuses are shown. If the endpoint returns 404 the screen says *SkladX tento modul zatiaľ nepodporuje* (the module works as soon as SkladX supports it).
- **New / edit receipt** (tabs *Detail*, *Edit receipt*, *Bulk add*, *Scan EAN*, *Attachments*):
  - header: *Name*, *Expected delivery date*, *Via carrier* (the plugin's carriers + *In person to the warehouse* = `personal` + *Other* with a free-text name), *Tracking number*, *Reference*, *Blind receipt*, note;
  - items: product autocomplete over WooCommerce products by name, SKU or EAN (only products with a SKU; variations are listed, not parents), quantity in pieces, batch, expiry; the same product can have several lines with different batches/expiries (identical lines are merged). Products not yet synced to SkladX are flagged with a *Sync to SkladX* button;
  - *Bulk add*: paste lines `code;quantity[;batch[;expiry]]` (code = SKU or EAN; `;`, tab or `,` as separator; header line and `#` comments skipped; expiry `YYYY-MM-DD`, `DD.MM.YYYY`, `MM/YYYY` = end of month). Invalid lines are reported with their line number, valid ones are added;
  - *Scan EAN*: a USB scanner works as a keyboard — every Enter adds pieces (default 1, optional batch/expiry for the scanned goods) to the matching line, with a beep;
  - *Attachments*: delivery note PDF or photos (PDF/JPG/PNG/WebP, max 10 MB) → `POST /inbound-receipts/{id}/attachments` (multipart, field `file`).
- **Flow:** *Save draft* = `POST /inbound-receipts` (draft, `Idempotency-Key: wc:<host>:inbound:<uuid>` per form) or `PUT /inbound-receipts/{id}` (only drafts); *Send to warehouse* = `POST …/{id}/announce` (status *Awaiting receipt*); *Cancel receipt* = `POST …/{id}/cancel` while nothing has been received; *Print label* = `GET …/{id}/label` (PDF opened in a new tab; ZPL download in Detail). After the warehouse receives the goods, Detail shows received quantities and differences per line.
- **Status updates:** webhooks `inbound.announced`, `inbound.arrived`, `inbound.received`, `inbound.cancelled` (also picked up by the `GET /events` polling) update the cache and show an admin notice once per status change (a warning when received with differences). Every 30 minutes open receipts are re-read as a fallback (`skladx_inbound_refresh`). Re-register the webhook after updating so it subscribes to the `inbound.*` events (older SkladX versions that reject them get a webhook without them).
- Payload: `{name, reference, expected_at, carrier, tracking_number, is_blind, note, warehouse_code, items: [{sku, quantity, batch, expires_at}]}`; filter `skladx_inbound_payload`.

### Webhook

`POST /wp-json/skladx/v1/webhook`

1. Verifies `X-SkladX-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "t.body")>` over the raw body, timestamp tolerance 300 s, constant-time compare. Invalid → `401`.
2. Stores the event in `wp_skladx_events` keyed by `X-SkladX-Event-Id` (atomic dedupe; duplicates answer `200 {"duplicate":true}`).
3. Answers `200` immediately; processing runs in Action Scheduler (`skladx_process_event`).

Handled events: `order.status_changed`, `order.shipped`, `order.delivered`, `order.delivery_failed`, `order.cancelled`, `order.returned`, `shipment.tracking_updated` (status, mapping, notes, tracking), `stock.changed`, `product.created`, `product.updated` (stock, if enabled), `inbound.*` (receipts, see above), `ping`. Stale events (older `status_changed_at`) are ignored. A new tracking number is added as a **customer note** (sends the "Customer note" email) and shown on the order page, in My account, in WooCommerce emails and in the admin SkladX box (*Track shipment* button). If SkladX sends no tracking URL, the public tracking page of the carrier is used (Packeta, DPD, GLS, Slovenská pošta).

**Polling fallback:** every 10 minutes `GET /events?after=<last evt_…>` (all pages, processed oldest first). Events the webhook missed go through the same inbox and dedupe. *Fetch missed events now* runs it on demand. The first run only remembers the newest event (no backfill). Changing the API key or URL resets the cursor.

### Uninstall

Deleting the plugin removes all `skladx_*` options except `skladx_site_key` (part of the product external ids in SkladX), the `wp_skladx_events` table and scheduled actions. Order and product meta (`_skladx_*`) is kept as part of the order history.

## Troubleshooting

- **Test connection → `invalid_api_key` / `insufficient_scope`:** issue a new key with the scopes listed above.
- **Orders stay "Queued":** Action Scheduler runs on WP-Cron. Check *WooCommerce → Status → Scheduled Actions* (group `skladx`); on low-traffic sites use a real cron (`wp action-scheduler run`).
- **Product shows *SkladX: error* / "SKU … already belongs to another SkladX product":** another SkladX product (possibly an older one created by SKU, or another shop product) owns the SKU. Change the SKU in WooCommerce, or rename/archive the other product in SkladX and click *Retry sync* on the product screen.
- **422 on export:** the message in the SkladX box says which field (unknown SKU — sync products first; invalid pickup point; missing address). Fix it and click *Resend to SkladX*.
- **Webhooks not arriving:** check *Webhooks → deliveries* in the SkladX portal. `401 invalid_signature` = wrong secret (re-register) or server clock off by more than 5 minutes. The shop must be reachable from SkladX over HTTPS with a valid certificate. Missed events are still picked up by polling.
- **Endpoint URL shows a server path** (`https://shop.test/Users/…/wp-json/…`): the site address (`home`/`siteurl` options) was saved wrongly, e.g. by an install run from the command line. Fix it (`wp search-replace` or *Settings → General*), then re-register the webhook. The settings page warns about such URLs.
- **Local development with Laravel Herd/Valet (`*.test`):** WordPress does not trust the local CA. Use a mu-plugin that adds the Herd CA (`~/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem`) via the `http_request_args` filter only for `*.test` hosts and only when `WP_ENVIRONMENT_TYPE` is `local`. Never disable SSL verification.

## Development

```bash
composer install      # dev only: PHPUnit
composer test         # unit tests: signature, payload mapping (incl. external_id, images), SKU conflict messages, status mapping, redaction, pickup points, receipts (payload, validation, bulk paste, webhook handling)
wp i18n make-pot . languages/skladx-woocommerce.pot --exclude=vendor,tests --skip-js
wp i18n update-po languages/skladx-woocommerce.pot languages/skladx-woocommerce-sk_SK.po
msgfmt -o languages/skladx-woocommerce-sk_SK.mo languages/skladx-woocommerce-sk_SK.po
```

Source strings are English; Slovak translation is in `languages/skladx-woocommerce-sk_SK.po`. Texts of the checkout and receipt scripts are passed from PHP (`skladxPickupConfig.i18n`, `skladxInbound.i18n`), so no JSON translation files are needed.

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
- **Otestovať pripojenie** – `GET /ping`, zobrazí klienta, prostredie a oprávnenia (upozorní aj na chýbajúce `inbound:read`/`inbound:write` pre príjemky).
- **Webhook** – tlačidlo *Zaregistrovať webhook v SkladX* ho vytvorí cez API a uloží podpisový kľúč. Manuálne: URL `https://vas-obchod/wp-json/skladx/v1/webhook` vložte do portálu SkladX a kľúč `whsec_…` do poľa *Podpisový kľúč webhooku*.
- **Odosielať objednávky v stave** – predvolene *Spracováva sa*; objednávka sa odošle raz.
- **Platobné metódy na dobierku** – suma objednávky sa pošle ako `cod_cents`.
- **Spôsoby dopravy → dopravcovia** – `packeta`, `dpd_sk`, `gls_sk`, `sps`, `posta_sk`, `balikovo`.
- **Výdajné miesto** – v tabuľke dopravcov stĺpec *Výdajné miesto* označí spôsoby dopravy na výdajné miesto (predvolene všetky namapované na `packeta`). V pokladni (klasickej aj blokovej) sa pri nich zobrazí tlačidlo *Vybrať výdajné miesto*: vyhľadávanie podľa mesta, PSČ alebo názvu, *V mojom okolí*, zoznam s otváracími hodinami a náhľad mapy. Miesta načítava plugin cez vlastné REST rozhranie (API kľúč SkladX sa do prehliadača nikdy nedostane), odpovede sa ukladajú do cache. Výber je povinný a server ho pri objednávke overí (existuje, je v prevádzke, pri dobierke prijíma dobierku). Kľúč `pickup_points:read` musí mať oprávnenie.
- **Meta kľúč výdajného miesta** – meta pole s ID výdajného miesta (predvolene `_skladx_pickup_point_id`); sem zapisuje výber výdajného miesta, SkladX miesto overí.
- **API kľúč widgetu Packeta** – nepovinné; ak je vyplnený, pri Packete sa otvorí oficiálny widget Packeta.
- **Stav SkladX → stav WooCommerce** – každá zmena je poznámka k objednávke, namapované stavy zmenia aj stav objednávky. Predvolene: `handed_over` → *Odoslaná (SkladX)*, `delivered` → *Vybavená*, `cancelled` → *Zrušená*.
- **Synchronizácia produktov** – hneď pri uložení produktu a tlačidlom *Synchronizovať všetky produkty* (upsert podľa `external_id`, potom podľa SKU; EAN z poľa *EAN pre SkladX*, GTIN alebo EAN pluginov), pozri nižšie.
- **Produkty bez SKU** – predvolene zapnuté: produkty a varianty bez SKU sa posielajú ako `WC-{id}`. Keď je voľba vypnutá, preskočia sa a v zozname produktov sú označené *Bez SKU*.
- **Synchronizácia zásob** – predvolene vypnutá; po zapnutí je zdrojom pravdy SkladX (dostupné = na sklade − rezervované). Pri zapnutí a tlačidlom *Prevziať zásoby zo SkladX teraz* sa prevezmú všetky stavy. Kusy z objednávok, ktoré ešte nie sú v SkladX, zostanú odpočítané.
- **Ladiaci log** – WooCommerce → Stav → Logy, zdroj `skladx`, tajné údaje maskované.

### Synchronizácia produktov

- **Stabilné `external_id`.** Každý produkt sa posiela s `external_id` = `wc:{site}:{id}` – pri jednoduchom produkte ID produktu, pri variácii ID variácie (napr. `wc:obchod.sk:57`). `{site}` je kľúč webu: hostiteľ adresy webu malými písmenami (`www.` zostáva), uložený raz do voľby `skladx_site_key` a už sa neprepočítava – po zmene domény obchodu zostane prepojenie so SkladX zachované (voľba zostane aj po odinštalovaní). SkladX hľadá produkt najprv podľa `external_id`, potom podľa SKU (produkty synchronizované pred verziou 0.4.0 sa prepoja pri ďalšej synchronizácii).
- **Premenovanie SKU.** Zmena SKU produktu, ktorý SkladX už pozná (rovnaké `external_id`), premenuje SKU v SkladX – nevznikne duplicitný produkt.
- **Konflikt SKU.** Ak nové SKU už patrí inému produktu v SkladX, SkladX odpovie `409 sku_conflict`. Plugin uloží chybu k produktu (ktorý produkt v SkladX má toto SKU: názov, SKU, ID a jeho prepojenie), zobrazí ju ako chybu pri úprave produktu a ako štítok *SkladX: chyba* v zozname produktov a automaticky to **neskúša znova** – rovnaké údaje sa znova neodošlú, kým produkt neuložíte so zmenou alebo nekliknete na *Synchronizovať znova*. Rovnako sa správajú chyby validácie (422).
- **Okamžitá synchronizácia so zálohou cez frontu.** Uložené produkty – v administrácii (aj nový produkt pri prvom zverejnení alebo SKU doplnené neskôr), variácie (aj *Uložiť zmeny* vo variáciách), REST API, import CSV, kôš/obnovenie (posiela sa ako `archived`) – sa odošlú na konci požiadavky s timeoutom 5 s, každý raz. Pri uložení variabilného produktu sa skontrolujú jeho variácie. Naraz sa odošle najviac 10 zmenených produktov, ostatné idú do fronty Action Scheduler. Sieťové chyby, 429 a 5xx sa opakujú po 1, 5, 15, 60, 180 a 360 minútach. Nezmenené údaje sa neposielajú, zmena SKU alebo chýbajúce prepojenie `external_id` sa odošle vždy.
- **Produkty bez SKU → `WC-{id}`.** SkladX potrebuje SKU pri každom produkte (príjem, štítky s čiarovým kódom). Produkt alebo variant bez vlastného SKU sa posiela ako `WC-{id}` (ID produktu, pri variante ID variantu, napr. `WC-57`) – kód sa nikdy nemení. Keď neskôr doplníte skutočné SKU, SkladX podľa `external_id` premenuje ten istý produkt (zásoby aj história zostanú). Rovnaký kód používajú položky objednávok, zásoby aj príjemky; do poľa SKU vo WooCommerce sa nezapisuje. Neplatí pre virtuálne produkty.
- **EAN** sa berie z poľa *EAN pre SkladX* (`_skladx_ean`), potom z poľa WooCommerce *GTIN, UPC, EAN alebo ISBN* (`global_unique_id`), potom z bežných EAN pluginov (`_ean`, `_gtin`, `_alg_ean`, `_wpm_gtin_code`, `hwp_product_gtin`, `hwp_var_gtin`, `_ts_gtin`, `_woosea_ean`).
- **Synchronizovať všetky produkty** (alebo `wp skladx sync-products`) odošle po dávkach všetky produkty a varianty vo všetkých stavoch okrem koša (aj koncepty); priebeh, výsledok a počet produktov bez SKU sa zobrazia v časti *Nástroje*. Po aktualizácii na 0.4.1 sa spustí raz automaticky, ak má obchod produkty bez SKU.
- **Obrázky.** `image_url` je absolútna URL hlavného obrázka (na HTTPS webe vždy `https://`), `images` obsahuje najviac 10 obrázkov galérie bez hlavného obrázka.
- Položky objednávky nesú `external_id` produktu alebo variácie (popri SKU).

### Príjemky (WooCommerce → Príjemky SkladX)

Ohlásenie tovaru, ktorý posielate do skladu. Zdrojom pravdy je SkladX – vo WordPresse sa ukladá len malá cache stavov pre upozornenia. Prístup s oprávnením `manage_woocommerce`, všetko ide cez admin-ajax s nonce, API kľúč sa do prehliadača nedostane. API kľúč potrebuje oprávnenia `inbound:read` a `inbound:write`.

- **Zoznam** príjemiek so stavmi (Koncept, Čaká na príjem, Na ceste, Prevzatá na sklade, Prijíma sa, Prijatá, Zrušená), dátumom dodania, dopravcom a odkazom na tracking, ohlásenými a prijatými kusmi s rozdielom, filtrom stavu a stránkovaním. Ak SkladX endpoint nepozná (404), zobrazí sa *SkladX tento modul zatiaľ nepodporuje*.
- **Nová príjemka / úprava** (záložky *Detail*, *Upraviť príjemku*, *Hromadné pridanie*, *Skenovanie EAN*, *Prílohy*): Názov, Predpokladaný dátum dodania, Cez dopravcu (dopravcovia pluginu + *Osobne na sklad* + *Iný*), Tracking, Referencia, Bianco príjemka, poznámka; položky s vyhľadávaním produktu podľa názvu, SKU alebo EAN (len produkty so SKU), počet kusov, šarža, expirácia, viac riadkov toho istého produktu. Produkty, ktoré ešte nie sú v SkladX, majú tlačidlo *Synchronizovať do SkladX*.
- **Hromadné pridanie:** riadky `kód;počet[;šarža[;expirácia]]` (kód = SKU alebo EAN, oddeľovač `;`, tabulátor alebo `,`, expirácia `RRRR-MM-DD`, `DD.MM.RRRR` alebo `MM/RRRR`); chybné riadky sa vypíšu s číslom riadku.
- **Skenovanie EAN:** USB skener funguje ako klávesnica – každý Enter pripočíta kusy k riadku produktu.
- **Prílohy:** dodací list alebo fotky (PDF, JPG, PNG, WebP do 10 MB).
- *Uložiť koncept* vytvorí/upraví koncept v SkladX, *Odoslať do skladu* ho ohlási (stav *Čaká na príjem*), *Tlačiť štítok* otvorí štítok príjemky na nalepenie na zásielku. Po prijatí sa v detaile zobrazia prijaté počty a rozdiely.
- Webhooky `inbound.*` (aj záložné načítanie udalostí a obnova každých 30 minút) aktualizujú stav a zobrazia upozornenie v administrácii. Po aktualizácii pluginu webhook zaregistrujte znova.

### Webhook a záložné načítanie

Webhook overuje HMAC podpis (tolerancia 300 s), deduplikuje podľa `X-SkladX-Event-Id`, odpovie hneď `200` a spracuje udalosť na pozadí. Číslo zásielky sa zákazníkovi pošle ako poznámka (e-mail) a zobrazí sa v detaile objednávky, v Mojom účte, v e-mailoch a v boxe SkladX (tlačidlo *Sledovať zásielku*). Každých 10 minút plugin načíta `GET /events?after=…`, aby zachytil zmeškané webhooky.

### Riešenie problémov

- **Produkt má *SkladX: chyba* / „SKU … už patrí inému produktu v SkladX“** – SKU má iný produkt v SkladX. Zmeňte SKU vo WooCommerce alebo upravte/archivujte druhý produkt v SkladX a pri produkte kliknite na *Synchronizovať znova*.
- **422 pri odoslaní** – v boxe SkladX pri objednávke je presná chyba (neznáme SKU → najprv synchronizujte produkty; neplatné výdajné miesto; chýbajúca adresa). Po oprave kliknite *Odoslať do SkladX znova*.
- **Objednávka visí vo fronte** – skontrolujte *WooCommerce → Stav → Naplánované akcie* (skupina `skladx`), na málo navštevovaných weboch nastavte skutočný cron.
- **Webhooky nechodia** – v portáli SkladX pozrite doručenia webhooku; `401` = zlý kľúč (zaregistrujte znova) alebo posunutý čas servera. Obchod musí byť dostupný cez HTTPS s platným certifikátom; zmeškané udalosti dobehne polling.
- **URL webhooku obsahuje cestu na serveri** (`https://obchod.test/Users/…/wp-json/…`) – adresa webu (voľby `home`/`siteurl`) je uložená nesprávne. Opravte ju a webhook zaregistrujte znova; stránka nastavení na takúto URL upozorní.
- **Lokálny vývoj (Herd, `*.test`)** – pridajte mu-plugin, ktorý pre hostiteľov `*.test` v lokálnom prostredí pridá certifikát Herd CA cez filter `http_request_args`. Overovanie SSL nikdy nevypínajte.

### Odinštalovanie

Zmaže nastavenia `skladx_*` okrem `skladx_site_key` (súčasť `external_id` produktov v SkladX), tabuľku `wp_skladx_events` a naplánované akcie. Meta údaje objednávok a produktov (`_skladx_*`) zostávajú.

## Changelog

### 0.4.1

- Products and variations without a SKU are no longer skipped: they are sent as the stable fallback SKU `WC-{id}` (setting *Products without SKU*, on by default; not for virtual products). Adding a real SKU later renames the same SkladX product via `external_id`. Orders, stock updates and receipts use the same code.
- Fix: a variation without its own SKU was sent with its parent's SKU, so the second variation of a product failed with `409 sku_conflict` and orders merged different variations into one line. Such variations now get `WC-{variation id}` (a variation already synced under the parent SKU keeps it).
- *Sync all products* now covers every status except trash (also drafts, pending and scheduled products) and every stockable product type, shows progress and the result under *Tools*, and is available as `wp skladx sync-products`. It runs once automatically after the update if the shop has products without a SKU.
- Products that cannot be sent for lack of a SKU are marked *No SKU* in the product list and counted under *Tools*.
- EAN is also read from common EAN/GTIN plugins (`_ean`, `_gtin`, `_alg_ean`, `_wpm_gtin_code`, `hwp_product_gtin`, `hwp_var_gtin`, `_ts_gtin`, `_woosea_ean`; filter `skladx_ean_meta_keys`); receipts find products by them too.

### 0.4.0

- Products are sent with a stable `external_id` (`wc:{site}:{product or variation id}`); the site key is stored once in `skladx_site_key`, so a domain change does not break the link. Order items carry the same `external_id` next to the SKU.
- Immediate product sync: saves from wp-admin (incl. first publish and a SKU added later), variations (incl. the ajax variations panel), REST API, CSV import and trash/restore are pushed at the end of the request (5 s timeout, once per product, max 10 per request); retryable failures (network, 429, 5xx) fall back to the Action Scheduler queue with backoff. A SKU change or a missing `external_id` link always forces a send.
- SKU rename in SkladX for known products; `409 sku_conflict` is stored on the product with the SkladX product that owns the SKU, shown on the product edit screen and in a new *SkladX* column of the product list, and never retried automatically — the same payload is not resent until the product changes or *Retry sync* is clicked. Same for 422 validation errors.
- Images: absolute (and on HTTPS sites https) `image_url`, plus `images` with up to 10 gallery images.
- Unit tests for external ids, images, order item external ids and SKU conflict handling; Slovak translation of the new texts.

### 0.3.1

- Receipts: received quantities and differences are shown only once the warehouse starts counting; an announced receipt no longer claims the warehouse received 0 pieces. The "received with differences" notice appears only for received receipts.

### 0.3.0

- New *WooCommerce → SkladX receipts* screen: list of inbound receipts (status badges, tracking link, received quantities and differences, status filter, cursor pagination) and an editor modelled on stock receipts in fulfilment portals — header (name, expected delivery date, carrier incl. *In person* / *Other*, tracking, reference, blind receipt), items with product autocomplete by name/SKU/EAN, batch and expiry (several lines per product), bulk paste with per-line errors, USB EAN scanning, attachments, label printing, *Send to warehouse*, cancel.
- SkladX stays the source of truth (draft via `POST`, edits via `PUT`, `announce`, `cancel`, `attachments`, `label`); all calls go through a nonce-protected admin-ajax proxy. Feature detection: without the SkladX endpoint the screen says the module is not supported yet.
- Webhooks/polling handle `inbound.announced`, `inbound.arrived`, `inbound.received`, `inbound.cancelled` (status cache + admin notice, warning on differences); periodic refresh of open receipts as a fallback. Webhook registration retries without `inbound.*` on SkladX versions that reject them. *Test connection* hints at missing `inbound:*` scopes.
- Client: raw/multipart requests and non-JSON responses (labels).
- Slovak translation of all new texts; unit tests for receipt payload, validation, bulk paste parsing and event handling.

### 0.2.0

- Pickup point picker at checkout (classic checkout and Checkout block) for pickup point shipping methods: search by city/postcode/name, near me, list with opening hours, COD/card badges and map preview. Points are loaded through a cached, rate-limited WP REST proxy (`skladx/v1/pickup-points`) — the SkladX API key stays on the server. The choice is required and validated on the server (exists, active, right carrier, accepts COD), stored in the pickup point meta key plus name/address meta and shown in admin, emails and the customer's order page.
- New settings: *Pickup point* column in the carrier table, *Pickup point picker*, optional *Packeta widget API key* (official Packeta widget instead of the built-in picker).
- Fix: stock sync ignored the `stock.changed` payload SkladX actually sends (`{product: {sku}, stock: {available}}`), so WooCommerce stock never changed.
- Stock sync: stale events no longer overwrite newer levels; units of orders not yet in SkladX stay deducted; turning stock sync on (or *Take over stock from SkladX now*) pulls all levels via `GET /stock`.
- *Track shipment* button and pickup point in the SkladX box on the order screen.

### 0.1.1

- Settings page warns when the webhook URL is not absolute, not HTTPS, or contains the server path of WordPress (wrong `home` option); new `skladx_webhook_url` filter.
- Export errors before the API call (line without SKU, missing address) are translated and tell the admin how to fix them.
- Editing the shipping address of an order already in SkladX adds a warning notice and order note (the SkladX API does not take over address changes).
- No double space in the order note when a SkladX status changes the WooCommerce status.

### 0.1.0

- First release.

## License

GPL-2.0-or-later.
