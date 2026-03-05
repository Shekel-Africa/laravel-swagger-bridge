# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-03-05

### Added
- `QueryRequest` abstract base class (`Shekel\SwaggerBridge\Http\Requests\QueryRequest`) for GET request query-parameter validation and automatic OpenAPI query-parameter schema generation.
- `make:query-request` Artisan command for scaffolding new `QueryRequest` subclasses (e.g. `php artisan make:query-request Users/ListUsersRequest`).
- PHPDoc-based schema extraction in `ResourceMapper`: the `@return` annotation on `toArray()` is now parsed using `phpstan/phpdoc-parser`, supporting generics (`array<string, mixed>`), array shapes (`array{id: int, name: string}`), nullable types, and union types.
- Added `phpstan/phpdoc-parser` (^2.3) as a required dependency to enable PHPDoc type parsing.

## [1.0.0] - 2026-02-17

### Added
- Initial release of Laravel Swagger Bridge.
- `RequestMapper` for mapping Laravel Form Requests to OpenAPI Request Bodies and Query Parameters.
- `ResponseMapper` for mapping Laravel JsonResources and Collections to OpenAPI Responses.
- `ResourceMapper` for extracting schemas from Laravel JsonResources.
- `LaravelProcessor` for automatic route detection and OpenAPI documentation generation.
- `SwaggerBridgeServiceProvider` for Laravel integration.

### Fixed
- Fixed `ResponseMapperTest` failures by correctly passing the `Analysis` object to the `map` method.
- Updated `ResponseMapper` to ensure a generic 200 response is added when no specific type hints are found.
