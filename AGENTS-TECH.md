# WooCommerce Sync Module - Technical Documentation

## Overview
Refactored FrontAccounting module for bidirectional sync with WooCommerce. Uses PHP 7.3+, PSR-4 autoloading, SOLID/SRP/DI/DRY principles, and the `automattic/woocommerce` v3.1.0 library.

## Namespace
`Ksfraser\FrontAccounting\Woocommerce`

## Architecture

### Core Components

| Component | Path | Purpose |
|-----------|------|---------|
| `WooRestClient` | `src/Ksfraser/frontaccounting/Woocommerce/` | HTTP client wrapper for WooCommerce REST API |
| `ProductService` | `src/Ksfraser/frontaccounting/Woocommerce/` | Product CRUD operations |
| `ProductExportService` | `src/Ksfraser/frontaccounting/Woocommerce/` | Export products to WooCommerce |
| `OrderExporter` | `src/Ksfraser/frontaccounting/Woocommerce/` | Export orders to WooCommerce |
| `CustomerExporter` | `src/Ksfraser/frontaccounting/Woocommerce/` | Export customers to WooCommerce |
| `CategoryExporter` | `src/Ksfraser/frontaccounting/Woocommerce/` | Export categories to WooCommerce |

### Staging Layer

| Component | Path | Purpose |
|-----------|------|---------|
| `CustomerStaging` | `src/Ksfraser/frontaccounting/Woocommerce/Staging/` | Stage WooCommerce customers for review |
| `OrderStaging` | `src/Ksfraser/frontaccounting/Woocommerce/Staging/` | Stage WooCommerce orders with payment extraction |

### Workflow Engine

| Component | Path | Purpose |
|-----------|------|---------|
| `WooSyncStateMachine` | `src/Ksfraser/frontaccounting/Woocommerce/Workflow/` | Module-specific state machine |
| `WorkflowStatusInterface` | `src/Ksfraser/frontaccounting/Woocommerce/Workflow/Status/` | Generic workflow status contract |
| `StagingStatusInterface` | `src/Ksfraser/frontaccounting/Woocommerce/Workflow/Status/` | Staging-specific statuses |
| `StateMachineInterface` | `src/Ksfraser/frontaccounting/Woocommerce/Workflow/StateMachine/` | State machine contract |

### Data Transfer Objects

| DTO | Purpose |
|-----|---------|
| `CustomerDTO` | Immutable customer data container |
| `OrderDTO` | Immutable order data container |
| `ProductDTO` | Immutable product data container |

### DAO Layer

| Component | Path | Purpose |
|-----------|------|---------|
| `SyncDao` | `src/Ksfraser/frontaccounting/Woocommerce/Dao/` | CRUD for sync mappings and audit logs |

### UI Layer

| Component | Path | Purpose |
|-----------|------|---------|
| `ImportExportDispatcher` | `src/Ksfraser/frontaccounting/Woocommerce/UI/` | Action dispatcher for import/export operations |

## Database Schema

### Tables (prefix: `{PREFIX}`)

1. **`{PREFIX}woo_customer_staging`** - Staged WooCommerce customers awaiting review
2. **`{PREFIX}woo_order_staging`** - Staged WooCommerce orders awaiting import
3. **`{PREFIX}woo_product_map`** - FA stock_id ↔ WooCommerce product ID mapping
4. **`{PREFIX}woo_category_map`** - FA category ID ↔ WooCommerce category ID mapping
5. **`{PREFIX}woo_sync_log`** - Audit trail for all sync operations

### Staging Table Fields

**Customer Staging:**
- `woo_customer_id`, `woo_order_id` - WooCommerce IDs
- `email`, `phone`, `first_name`, `last_name` - Contact info
- `company`, `address1`, `address2`, `city`, `state`, `postcode`, `country` - Address
- `raw_data` - JSON blob for extensibility
- `imported`, `imported_at` - Import status
- `fa_debtor_no`, `fa_branch_ref` - Mapped FA customer
- `staged_at` - Staging timestamp

**Order Staging:**
- `woo_order_id`, `woo_status` - Order identification
- `email`, `total`, `currency` - Order details
- `raw_data` - JSON with `payment_method`, `transaction_id`, `date_paid`, `amount`
- `imported`, `imported_at` - Import status
- `fa_order_no`, `fa_debtor_no`, `fa_branch_ref` - Mapped FA sales order
- `staged_at` - Staging timestamp

## FrontAccounting Integration

### Security Areas

| Constant | Value | Purpose |
|----------|-------|---------|
| `SA_WOOCOMMERCE_SYNC` | SS_WOOCOMMERCE_SYNC \| 1 | Main sync interface |
| `SA_WOOCOMMERCE_IMPORT` | SS_WOOCOMMERCE_SYNC \| 2 | Import data |
| `SA_WOOCOMMERCE_EXPORT` | SS_WOOCOMMERCE_SYNC \| 4 | Export data |
| `SA_WOOCOMMERCE_STAGING` | SS_WOOCOMMERCE_SYNC \| 8 | Review staging |

### Hooks Entry Points

| App | Menu Item | File |
|-----|-----------|------|
| `stock` | WooCommerce Sync | `public/index.php` |
| `orders` | Import Woo Orders | `admin/import_orders.php` |
| `customers` | Import Woo Customers | `admin/import_customers.php` |

### Configuration (FA Company Preferences)

| Key | Purpose |
|-----|---------|
| `woocommerce_url` | WooCommerce REST API URL |
| `woocommerce_key` | WooCommerce API consumer key |
| `woocommerce_secret` | WooCommerce API consumer secret |

## Workflow Statuses

### Generic Workflow (WorkflowStatusInterface)
- `pending`
- `in_progress`
- `completed`
- `error`
- `failed`
- `cancelled`

### Staging (StagingStatusInterface)
- `staged`
- `pending_review`
- `matched`
- `processing`
- `processed`
- `imported`

## External Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `automattic/woocommerce` | ^3.1.0 | Official WooCommerce REST API client |
| `ksfraser/ksf-workflow` | dev-main | Generic workflow patterns (interfaces/traits) |

## Test Suite

```bash
./vendor/bin/phpunit tests/Unit/
```

- **105 tests** with **184 assertions** (as of last run)
- Bootstrap: `tests/bootstrap.php` pre-loads classes for autoload compatibility

## Legacy Files

35 PHP files in root marked with `@deprecated` tags (~10k lines total). These wrap legacy `wc-master` client and should be phased out.

## Key Design Decisions

1. **All WooCommerce orders → FA Sales Orders** (not direct invoices) to trigger shipping/packaging workflow
2. **Payment data stored in `raw_data` JSON** for later processing
3. **Generic workflow patterns in `ksf-workflow`** package, not duplicated in this module
4. **Namespace uses lowercase**: `Ksfraser\Frontaccounting\Woocommerce` (FA convention)

## File Structure

```
export_woocommerce/
├── hooks.php                 # FA module hooks
├── public/
│   └── index.php             # Main sync UI
├── admin/
│   ├── import_orders.php     # Order import page
│   └── import_customers.php  # Customer staging review
├── sql/
│   └── schema.sql            # Database schema
├── src/Ksfraser/frontaccounting/Woocommerce/
│   ├── Staging/              # Staging services
│   ├── Workflow/             # State machine
│   ├── Dao/                  # Data access
│   ├── DTO/                  # Data containers
│   └── UI/                   # Dispatcher
├── tests/
│   ├── Unit/                 # Unit tests
│   └── bootstrap.php
├── vendor/                   # Composer dependencies
└── composer.json
```
