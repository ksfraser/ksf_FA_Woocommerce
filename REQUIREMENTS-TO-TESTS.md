# Requirements Traceability Matrix (RTM)
# WooCommerce Sync Module for FrontAccounting
# Mapping requirements to test cases

## Requirements Source
Based on AGENTS-TECH.md technical documentation

## Requirement ID Format
REQ-<AREA>-<NN>
Where AREA is one of:
- ARCH: Architecture
- DB: Database
- FA: FrontAccounting Integration
- SEC: Security
- CONF: Configuration
- WORKFLOW: Workflow Engine
- DTO: Data Transfer Objects
- DAO: Data Access Objects
- UI: User Interface
- TEST: Testing
- DESIGN: Design Decisions

## Requirements List

### Architecture Requirements
REQ-ARCH-001: Module uses PSR-4 autoloading with namespace `Ksfraser\FrontAccounting\Woocommerce`
REQ-ARCH-002: Module follows SOLID principles (particularly SRP - Single Responsibility Principle)
REQ-ARCH-003: Module uses Dependency Injection for service dependencies
REQ-ARCH-004: Module avoids code duplication (DRY principle)
REQ-ARCH-005: Generic workflow patterns are in separate `ksf-workflow` package, not duplicated

### Core Component Requirements
REQ-ARCH-006: `WooRestClient` provides HTTP client wrapper for WooCommerce REST API
REQ-ARCH-007: `ProductService` handles product CRUD operations
REQ-ARCH-008: `ProductExportService` exports products to WooCommerce
REQ-ARCH-009: `OrderExporter` exports orders to WooCommerce
REQ-ARCH-010: `CustomerExporter` exports customers to WooCommerce
REQ-ARCH-011: `CategoryExporter` exports categories to WooCommerce

### Staging Layer Requirements
REQ-ARCH-012: `CustomerStaging` stages WooCommerce customers for review before import
REQ-ARCH-013: `OrderStaging` stages WooCommerce orders with payment extraction

### Workflow Engine Requirements
REQ-WORKFLOW-001: `WooSyncStateMachine` implements module-specific state machine
REQ-WORKFLOW-002: Workflow uses generic interfaces from `ksf-workflow` package
REQ-WORKFLOW-003: `WorkflowStatusInterface` defines generic workflow status contract
REQ-WORKFLOW-004: `StagingStatusInterface` defines staging-specific statuses
REQ-WORKFLOW-005: `StateMachineInterface` defines state machine contract

### DTO Requirements
REQ-DTO-001: `CustomerDTO` provides immutable customer data container
REQ-DTO-002: `OrderDTO` provides immutable order data container
REQ-DTO-003: `ProductDTO` provides immutable product data container

### DAO Layer Requirements
REQ-DAO-001: `SyncDao` provides CRUD operations for sync mappings
REQ-DAO-002: `SyncDao` provides CRUD operations for audit logs

### UI Layer Requirements
REQ-UI-001: `ImportExportDispatcher` dispatches import/export actions
REQ-UI-002: UI layer coordinates between exporters and staging services

### Database Schema Requirements
REQ-DB-001: `{PREFIX}woo_customer_staging` table stores staged customer data
REQ-DB-002: `{PREFIX}woo_order_staging` table stores staged order data
REQ-DB-003: `{PREFIX}woo_product_map` table maps FA stock_id to WooCommerce product ID
REQ-DB-004: `{PREFIX}woo_category_map` table maps FA category ID to WooCommerce category ID
REQ-DB-005: `{PREFIX}woo_sync_log` table provides audit trail for sync operations

REQ-DB-006: Customer staging table includes fields for WooCommerce IDs, contact info, address, raw data, import status, FA mapping
REQ-DB-007: Order staging table includes fields for WooCommerce order ID, status, email, total, currency, raw data, import status, FA mapping
REQ-DB-008: Product mapping table includes stock_id, woo_product_id, woo_product_url, last_synced, sync_status
REQ-DB-009: Category mapping table includes fa_category_id, woo_category_id, last_synced
REQ-DB-010: Sync log table includes sync_type, action, reference_id, success, error_message, sync_data, timestamps

### FrontAccounting Integration Requirements
REQ-FA-001: Module integrates as a FrontAccounting module with hooks.php
REQ-FA-002: Module defines security areas: SS_WOOCOMMERCE_SYNC with SA_WOOCOMMERCE_SYNC/IMPORT/EXPORT/STAGING
REQ-FA-003: Module adds menu items under Stock, Orders, and Customers applications
REQ-FA-004: Module installs access rights and sections correctly
REQ-FA-005: Module activates/deactivates correctly with hook registration
REQ-FA-006: Module ensures database schema on activation
REQ-FA-007: Module retrieves WooCommerce configuration from company preferences
REQ-FA-008: Module caches service instances and configuration

### Security Requirements
REQ-SEC-001: `SS_WOOCOMMERCE_SYNC` defined as stock area section (116 << 8)
REQ-SEC-002: `SA_WOOCOMMERCE_SYNC` = SS_WOOCOMMERCE_SYNC | 1 (main sync)
REQ-SEC-003: `SA_WOOCOMMERCE_IMPORT` = SS_WOOCOMMERCE_SYNC | 2 (import data)
REQ-SEC-004: `SA_WOOCOMMERCE_EXPORT` = SS_WOOCOMMERCE_SYNC | 4 (export data)
REQ-SEC-005: `SA_WOOCOMMERCE_STAGING` = SS_WOOCOMMERCE_SYNC | 8 (review staging)

### Configuration Requirements
REQ-CONF-001: WooCommerce URL stored in company preference 'woocommerce_url'
REQ-CONF-002: WooCommerce API Key stored in company preference 'woocommerce_key'
REQ-CONF-003: WooCommerce API Secret stored in company preference 'woocommerce_secret'
REQ-CONF-004: Preferences appear in company setup form

### Workflow Status Requirements
REQ-WORKFLOW-006: Generic workflow statuses: pending, in_progress, completed, error, failed, cancelled
REQ-WORKFLOW-007: Staging statuses: staged, pending_review, matched, processing, processed, imported
REQ-WORKFLOW-008: State machine supports canTransition() and transition() methods
REQ-WORKFLOW-009: State machine maintains transition history

### Design Decision Requirements
REQ-DESIGN-001: All WooCommerce orders create FA Sales Orders (not direct invoices)
REQ-DESIGN-002: Payment data stored in `raw_data` JSON field for later processing
REQ-DESIGN-003: Namespace uses lowercase: `Ksfraser\Frontaccounting\Woocommerce` (FA convention)
REQ-DESIGN-004: Legacy PHP files marked with `@deprecated` tags for phased removal

### Testing Requirements
REQ-TEST-001: Test suite achieves high code coverage (target: 100%)
REQ-TEST-002: All tests pass without failures or skips
REQ-TEST-003: Bootstrap.php pre-loads classes for PHPUnit autoload compatibility
REQ-TEST-004: Tests use proper mocking for dependencies

### File Structure Requirements
REQ-STRUCT-001: Module follows standard FA module structure with hooks.php, public/, admin/, sql/
REQ-STRUCT-002: Source code organized in src/Ksfraser/frontaccounting/Woocommerce/ sub-namespaces
REQ-STRUCT-003: Tests organized in tests/Unit/ with bootstrap.php
REQ-STRUCT-004: Composer.json includes dependency on ksfraser/ksf-workflow
REQ-STRUCT-005: Documentation provided in AGENTS-TECH.md

## Test Case Mapping Template
To be filled as tests are written:

| Requirement ID | Requirement Description | Test Method(s) | Test File | Status (Pass/Fail) | Notes |
|----------------|-------------------------|----------------|-----------|-------------------|-------|
| REQ-ARCH-001   | Module uses PSR-4 autoloading with namespace `Ksfraser\FrontAccounting\Woocommerce` | testNamespaceExists, testNamespaceIsCorrect, testUsesLowercaseFrontaccounting | tests/Unit/NamespaceTest.php | Pass | Confirms namespace exists, is correct, and uses lowercase 'frontaccounting' per FA convention |
| REQ-DTO-001    | `CustomerDTO` provides immutable customer data container | testCanBeCreatedWithValidDataUsingBillingKey, testCanBeCreatedWithMinimalDataWithoutBillingKey, testGettersReturnCorrectTypes, testIsImmutableAfterConstruction, testFromWooCommerceCreatesDtoWithTopLevelFields, testFromWooCommerceHandlesMissingFields | tests/Unit/DTO/CustomerDTOTest.php | Pass | Tests construction, getters, immutability, and factory method |
| ...            |                         |                |           |                   |       |

## Coverage Tracking
As tests are implemented, update this matrix with:
- Which test methods cover each requirement
- Pass/fail status of those tests
- Notes on any gaps or partial implementations

## Next Steps
1. Review and validate this RTM with stakeholders
2. For each requirement, identify existing tests or create new test cases
3. Update the mapping table above
4. Measure coverage and identify gaps
5. Implement additional tests to achieve 100% requirements coverage
