# Customer Matching Algorithm — export_woocommerce

This document describes the customer matching algorithm used by the WooCommerce module's `CustomerStaging` class. It is more sophisticated than the generic `MatchingService::matchByCustomer()` in `ksf_FA_ImportStagingProcessing` and should be examined for adoption into the generic service.

## Source
`src/Ksfraser/frontaccounting/Woocommerce/Staging/CustomerStaging.php` — methods `findMatches()` and `calculateMatchScore()`

## Overview
Matches staged WooCommerce customer data against existing FA `debtors_master` + `branches` records. Returns a sorted list of candidates each with a 0–100 confidence score.

## Data Sources Queried

```sql
SELECT dm.debtor_no, dm.name, dm.email, dm.curr_code,
       bm.branch_ref, bm.br_name, bm.contact_name,
       bm.phone, bm.email AS branch_email,
       bm.br_address
FROM {prefix}debtors_master dm
LEFT JOIN {prefix}branches bm ON dm.debtor_no = bm.debtor_ref
```

Every debtor + all their branches are candidates. No WHERE filtering — all FA customers are scored against the staged record.

## Scoring Dimensions (total 0–100)

| Dimension | Max Points | What It Checks | Method |
|---|---|---|---|
| **Email** | 30 | Staged `email` == `debtors_master.email` (case-insensitive) | Exact string match |
| **Email (branch)** | 30 | Staged `email` == `branches.email` (case-insensitive) | Exact string match |
| **Phone** | 25 | Staged `phone` vs `branches.phone`, digits-only normalization | Exact normalized string match |
| **Name / Company** | 20 | Staged `company` vs `debtors_master.name` | Fuzzy: exact OR substring OR Levenshtein distance < 20% of string length |
| **Contact Name** | 20 | Staged `first_name + ' ' + last_name` vs `branches.contact_name` | Fuzzy: exact OR substring OR Levenshtein distance < 20% |
| **Address** | 15 | Staged `address1` vs `branches.br_address` | Normalized substring containment (normalize: lowercase, collapse whitespace) |

**Score cap**: `min(100.0, score)` — a single email match + phone match = 55, which exceeds the auto-select threshold.

**Note**: Email and branch-email are cumulative. A match on both yields 60 points alone.

## Auto-Select Threshold

```php
private const SCORE_HIGH_MATCH = 50.0; // Auto-select if above
```

Any candidate scoring ≥ 50 is considered a strong enough match for automatic selection. This threshold was chosen because:
- Email match (30) + phone match (25) = 55 (auto-select)
- Email match (30) + name match (20) = 50 (auto-select)
- Individual dimensions are intentionally below 50, requiring corroboration

## Fuzzy Match Details

```php
private function fuzzyMatch(string $a, string $b): bool
{
    $a = strtolower(trim(preg_replace('/\s+/', ' ', $a)));
    $b = strtolower(trim(preg_replace('/\s+/', ' ', $b)));

    if ($a === $b) return true;              // exact match
    if (strpos($a, $b) !== false || strpos($b, $a) !== false) return true; // substring

    // Levenshtein distance < 20% of string length
    $dist = levenshtein($a, $b);
    $len = max(strlen($a), strlen($b));
    return $len > 0 && ($dist / $len) < 0.2;
}
```

Three-tier fuzzy: exact → substring → Levenshtein (< 20% distance).

## Address Match Details

```php
private function normalizeAddress(string $addr): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $addr)));
}
```

Both staged `address1` and branch `br_address` are normalized. Match succeeds if one contains the other (substring containment, not equality).

## Return Format

```php
[
    [
        'debtor_no'  => 42,
        'branch_ref' => 'BR-42-a1b2c3',
        'name'       => 'ACME Corp',
        'company'    => 'ACME Corp',
        'score'      => 55.0,
        'email'      => 'billing@acme.com',
        'phone'      => '555-0100',
    ],
    // ...sorted descending by score
]
```

Results are sorted highest-score-first. All candidates with score > 0 are returned.

## Comparison with ksf_FA_ImportStagingProcessing MatchingService

| Feature | Ours (CustomerStaging) | Generic (MatchingService) |
|---|---|---|
| **Scope** | Customer-to-FA-debtor matching | Transaction matching (amount, date, ref) primarily |
| **Customer method** | `calculateMatchScore()` — dedicated, FA-specific | `matchByCustomer()` — basic, averages sub-scores |
| **Email** | Exact, checks both `email` + `branch_email` (30pts each) | Exact only (0 or 1.0) |
| **Phone** | Digits-only normalized exact (25pts) | Digits-only + last-7 fallback (0.7 for partial) |
| **Name** | Levenshtein < 20%, substring, exact — boolean pass/fail (20pts) | `similar_text()` continuous 0–1.0 |
| **Address** | Substring containment (15pts) | ❌ Not present |
| **Company vs Name** | Yes — staged company vs debtor name | ❌ Not present |
| **Scale** | 0–100, business-friendly | 0–1.0, academic |
| **Auto-select** | ≥ 50 points (clearly documented threshold) | ≥ 0.95 confidence |
| **Review needed** | < 50 points | 0.80–0.94 |
| **Unmatched** | 0 points | < 0.80 |

## Recommendations for Generic Module

1. Adopt the 0–100 scoring scale for customer matching — easier for business users to understand
2. Add email matching against both `debtors_master.email` and `branches.email`
3. Add address substring containment matching
4. Add company vs debtor name matching
5. Add Levenshtein-based fuzzy matching (or replace `similar_text` which is slower and less intuitive)
6. Use the staged `first_name + last_name` concatenation for contact name matching
