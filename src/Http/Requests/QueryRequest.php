<?php

namespace Shekel\SwaggerBridge\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base class for GET-request query parameter validation and Swagger schema
 * generation.
 *
 * Concrete subclasses implement {@see queryRules()} to declare the accepted
 * query parameters.  The bridge's RequestMapper reads queryRules() to build
 * the OpenAPI query-parameter list automatically.
 *
 * Usage
 * -----
 * Generate a concrete class with:
 *   php artisan make:query-request Users/ListUsersRequest
 *
 * Then type-hint it in your controller method:
 *   public function index(ListUsersRequest $request): JsonResource { ... }
 */
abstract class QueryRequest extends FormRequest
{
    /**
     * Define the query parameter validation rules.
     *
     * These rules are used both by Laravel's validation pipeline and by the
     * Swagger bridge to build the OpenAPI query-parameter schema.
     *
     * @return array<string, mixed>
     */
    abstract public function queryRules(): array;

    /**
     * Delegate rules() to queryRules() so that Laravel validates the query
     * parameters declared in queryRules() automatically.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->queryRules();
    }
}
