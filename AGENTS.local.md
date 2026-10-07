# AGENTS.local.md — ksf_FA_Woocommerce

Repo-specific operational notes. Cross-module conventions live in the shared
`AGENTS_ARCH.md` (hardlinked).

## Layout

`src/Woocommerce/` — PSR-4 root is `ksfraser\FrontAccounting\Woocommerce\`.
The old path `src/Ksfraser/frontaccounting/Woocommerce/` was removed in commit
`b32c0d8`: it nested the vendor name inside `src/`, mixed case (`Ksfraser` vs
`frontaccounting`), and only worked because nothing case-collided. Match the
namespace root to the directory.

## Staging dispatch

`IsuStagingGateway` dispatches by capability (`hook_invoke_first`, with a
`hook_invoke_all` fallback), never by naming a stager module. Woo is a source
system; a replacement stager must be able to take over without editing Woo.

`hooks.php` `stage_customer` / `stage_order` / `stage_payment` use
`invokeStagingCapability()` + `mergeStagingResponse()`; the return shape is still
the merged payload plus `staged` / `success` / `error` / `staging_id`.

`STAGE_WEBHOOK_EVENT` is intentionally left as a broadcast — it is a genuine
fire-and-forget event with no responder expecting a reply.

## Known gap: four handlers stage nothing yet

`stage_tax_woo`, `stage_coupon`, `stage_product` and `stage_shipping` dispatch a
**raw array** to `STAGE_ENTITY`, but ISU's `STAGE_ENTITY` requires a
`\Ksfraser\StagingDto\StagingEntity` instance and rejects anything else. As of
commit `b30c1b3` the rejection is reported back (`staged => false`, `error`
populated) instead of being swallowed, but **nothing is actually staged**.

`staging-dto` provides `StagingProduct`, `StagingCoupon`, `StagingShipment` and
`StagingTax`, but **none of those four are supported by ISU's `DtoAdapter`**,
which stages only `StagingOrder`, `StagingInvoice`, `StagingPayment`,
`StagingRefund`, `StagingSubscription`, `StagingCustomer`, `StagingProduct`,
`StagingProductVariant` and `StagingCategory`. Anything else raises
`InvalidArgumentException('Unsupported DTO type: ...')`.

So of these four handlers, only `stage_product` could work today (StagingProduct
is supported); coupon, shipment and tax need adapter support added first, which
means new staging tables and a design decision. Building them is still open.
`StagingResponseNotDiscardedTest` pins the current loud failure so the gap
cannot silently return.
