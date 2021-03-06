# Changelog

All Notable changes to `guzzle_retry_middleware` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](http://keepachangelog.com/) principles.

## v2.2 (2018-06-03)

### Added
- Added `expose_retry_header` and `retry_header` options for debugging purposes (thanks, @coudenysj)
- Travis CI now tests PHP v7.2

### Changed
- Allow newer versions of PHPUnit in `composer.json` (match Guzzle composer.json PHPUnit requirements)

### Fixed
- Refactored data provider method name in PHPUnit test (`testRetryOccursWhenStatusCodeMatchesProvider` 
  → `providerForRetryOccursWhenStatusCodeMatches`)
- Use PHPUnit new namespaced class name
- Fix `phpunit.xml.dist` specification so that PHPUnit no longer emits warnings
- Travis CI should use lowest library versions on lowest supported version of PHP (v5.5, not 5.6)  

### Removed
- `hhvm` tests in Travis CI; they were causing builds to fail

## v2.1 (2018-02-13)

### Added
- Added `retry_enabled` parameter to allow quick disable of retry on specific requests
- Added ability to pass in a callable to `default_retry_multiplier` in order to implement custom delay logic

## v2.0 (2017-10-02)

### Added
- Added ability to retry on connect or request timeout (`retry_on_timeout` option)
- Added better tests for retry callback

### Changed
- Changed callback signature for `on_retry_callback` callback.  Response object is no longer guaranteed to be present,
  so the callback signature now looks like this: 
  `(int $retryCount, int $delayTimeout, RequestInterface $request, array $options, ResponseInterface|null $response)`.
- Updated Guzzle requirement to v6.3 or newer

### Fixed
- Clarified and cleaned up some documentation in README, including a typo.

## v1.0 (2017-07-29)

### Added
- Everything; this is the initial version.
