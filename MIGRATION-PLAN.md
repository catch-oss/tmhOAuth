# Migration Plan: tmhOAuth

## Summary

- **Package**: themattharris/tmhoauth
- **Type**: A (Pure PHP library)
- **Tier**: 1
- **Risk Level**: Low
- **Estimated Scope**: 1 file, 1 class (~965 lines)

## Change Inventory

### Namespace Renames Required

None — Type A library with no Silverstripe dependencies.

### Composer Dependency Changes

| Package | Current Version | Target Version |
|---|---|---|
| php | >=8.1 | ^8.5 |
| phpunit/phpunit | (not present) | ^11.0 (dev) |

### API Changes Required

None — no Silverstripe API usage.

### PHP 8.5 Compatibility Fixes

| Issue | Fix | Files Affected |
|---|---|---|
| Missing return type declarations | Add return types to all methods | tmhOAuth.php |
| Missing parameter type hints | Add type hints where safe | tmhOAuth.php |
| `strpos() !== false` patterns | Replace with `str_contains()` | tmhOAuth.php (lines 338, 762, 838) |
| `strpos() === 0` patterns | Replace with `str_starts_with()` | tmhOAuth.php (lines 409, 412) |
| `stripos() === 0` pattern | Replace with `str_starts_with(strtolower())` or keep | tmhOAuth.php (line 762) |
| Obsolete `defined('__DIR__')` guard | Remove (built-in since PHP 5.3) | tmhOAuth.php (line 20) |
| `array()` syntax | Modernize to `[]` for consistency | tmhOAuth.php (throughout) |

### PHPUnit Migration

| Issue | Fix | Files Affected |
|---|---|---|
| No test suite exists | Create PHPUnit 11 test suite from scratch | tests/ (new) |
| No phpunit.xml | Create PHPUnit 11 config | phpunit.xml (new) |

### Config Changes

None — standalone PHP library with no config files.

### Logging Integration

Not applicable — standalone library, no Silverstripe injector or Monolog integration needed.

## Risk Assessment

| Area | Risk | Notes |
|---|---|---|
| Namespace renames | N/A | No SS namespaces |
| API changes | N/A | No SS API usage |
| PHP 8.5 compat | Low | Mostly adding type hints and modernizing string functions |
| Test migration | Low | No existing tests to break; creating fresh PHPUnit 11 suite |
| Config changes | N/A | No config files |
| Overall | Low | Single-file library, no dependencies beyond PHP + curl |

## Migration Steps (Ordered)

### Phase 1: composer.json
- [ ] Update `php` requirement from `>=8.1` to `^8.5`
- [ ] Add `require-dev` section with `phpunit/phpunit: ^11.0`
- [ ] Add `autoload-dev` PSR-4 mapping for tests
- [ ] Run `composer validate`

### Phase 2: Namespace Renames
- [ ] N/A — no Silverstripe namespaces

### Phase 3: API Changes
- [ ] N/A — no Silverstripe API usage

### Phase 4: PHP 8.5 Compatibility
- [ ] Remove obsolete `defined('__DIR__')` guard (line 20)
- [ ] Add return type declarations to all methods (`: void`, `: string`, `: array`, `: int`, `: bool`, `: static`)
- [ ] Add parameter type hints where safe (`array`, `string`, `bool`, `int`)
- [ ] Replace `strpos($x, $y) !== false` with `str_contains($x, $y)` (3 locations)
- [ ] Replace `strpos($x, $y) === 0` with `str_starts_with($x, $y)` (2 locations)
- [ ] Replace `stripos($x, $y) === 0` with appropriate modern equivalent
- [ ] Modernize `array()` syntax to `[]` throughout
- [ ] Verify no `${var}` interpolation (none found — all use `{$var}` or `$var`)

### Phase 5: Logging Integration
- [ ] N/A — standalone library

### Phase 6: Config Updates
- [ ] N/A — no config files

### Phase 7: Test Suite
- [ ] Create `phpunit.xml` with PHPUnit 11 schema
- [ ] Create `tests/` directory with basic test class
- [ ] Write unit tests for: `nonce()`, `timestamp()`, `safe_encode()`, `safe_decode()`, `extract_params()`, `url()`, `transformText()`, `bearer_token_credentials()`
- [ ] Write unit tests for OAuth signing (HMAC-SHA1, HMAC-SHA256) using known test vectors
- [ ] Target 80% code coverage on testable methods (curl methods may need mocking)

## Dependencies

- **Depends on**: Nothing (Tier 1, no internal dependencies)
- **Blocks**: None directly, but Tier 1 completion unblocks the overall migration pipeline
