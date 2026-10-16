# ksf_FA_Woocommerce

Woocommerce Export module

**Version:** `2.4.3-1` &nbsp;|&nbsp; **FA:** 2.4 &nbsp;|&nbsp; **PHP:** 7.3

---

## What this module does

Woocommerce Export module

> No `ProjectDcs/BR*.md` recorded yet - business requirements for this
> module have not been written down. See *Contributing* below.

## Installing

This is a FrontAccounting extension; it installs into the FA `modules/` tree.

```bash
rsync -a --exclude .git --exclude vendor \
    ~/Documents/ksf_FA_Woocommerce/ ~/Documents/ksf_Infrastructure/fa_modules/ksf_FA_Woocommerce/
cd ~/Documents/ksf_Infrastructure/fa_modules/ksf_FA_Woocommerce \
    && composer install --no-dev
```

Then in the FA UI: **Setup -> Extensions**, tick the module and press
**Refresh/Update**. FA reads `_init/config` for the package name and
version, so that file must stay in sync with this README.

Notes:

- Do **not** deploy `vendor/` from the dev checkout - it carries dev
  dependencies (PHPUnit needs PHP >= 8.1) that fatal on the 7.4 runtime.
- Never edit anything under `fa_modules/` by hand. It is build output;
  development happens in `~/Documents/ksf_FA_Woocommerce`.

## Where to find it in the UI

Page entry points (each enforces its own `$page_security` area):

| Page | Security area |
|------|----------------|
| `admin.php` | `SA_WOOCOMMERCE_STAGING` |

- Hooks class: `hooks_ksf_FA_Woocommerce`
- Security sections defined: `SS_WOOCOMMERCE_SYNC`
- **Extensions -> Setup** decides whether the module may run at all;
  grant the areas above under *Access* before the pages appear.

Menu placement is added by the page code (see `pages/` above); if a tab is
missing, check that the module is activated for your company and that your
user holds the listed security areas.

## Repository layout

```
ksf_FA_Woocommerce/
├── hooks.php            # FA extension entry point, security areas
├── _init/config         # FA package manifest (plain Key: Value)
├── composer.json        # PSR-4 autoload, ksfraser/* dependencies
├── pages/               # FA pages, one $page_security each
├── src/                 # PSR-4 classes
├── includes/            # procedural FA helpers
├── sql/                 # schema (literal 0_ table prefix)
├── tests/               # PHPUnit suite
├── ProjectDcs/          # BABOK documents
└── vendor/              # not deployed; composer install --no-dev in fa_modules
```

## Contributing

1. Work in `~/Documents/ksf_FA_Woocommerce` - never in `fa_modules/`.
2. Branch: `feat/`, `fix/`, `refactor/`, `docs/`.
3. Tests first: `cd ~/Documents/ksf_FA_Woocommerce && php vendor/bin/phpunit`.
4. Requirements live in `ProjectDcs/` as individual files
   (`BR-<MOD>-<seq>-<slug>.md`, `FR-...`, `UC-...`, `UT-...`), and code
   carries `@BABOK Related: <ID>` in its docblock so traceability can be
   generated from the source.
5. Commit per logical change, then push. Deploys are rsync from dev;
   they must never carry commits of their own.

Conventions that apply across every module are documented in
`AGENTS_ARCH.md` (shared, hardlinked) and `AGENTS_APPENDIX.md`.
