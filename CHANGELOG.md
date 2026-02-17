# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
