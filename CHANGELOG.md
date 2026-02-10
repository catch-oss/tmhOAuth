# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed
- Updated PHP requirement from `>=8.1` to `^8.5`
- Added typed properties, parameter type hints, and return type declarations throughout
- Replaced `strpos()` patterns with `str_contains()` and `str_starts_with()`
- Modernized `array()` syntax to `[]` and `list()` to `[]` destructuring
- Removed obsolete `defined('__DIR__')` guard (built-in since PHP 5.3)
- `streaming_request()` return type changed to `void`

### Added
- PHPUnit 11 test suite with 51 tests (74% line coverage)
- `phpunit.xml` configuration for PHPUnit 11
- `composer.json` autoload-dev mapping for test namespace
- GitHub Actions CI workflows (PHP 8.5 tests, Claude AI review, SonarCloud)
- MIGRATION-PLAN.md documenting all SS6 migration changes
- `.gitignore` for vendor, coverage, and cache directories
