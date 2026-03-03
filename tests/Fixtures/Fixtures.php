<?php

namespace Shekel\SwaggerBridge\Tests\Fixtures;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Database\Eloquent\Model;

class TestController extends Controller
{
    /**
     * Test Summary
     * Test Description Line 2
     */
    public function index(TestGetRequest $request): AnonymousResourceCollection
    {
        return TestResource::collection(collect([]));
    }

    /**
     * Create Summary
     */
    public function store(TestPostRequest $request): TestResource
    {
        return new TestResource([]);
    }

    public function show(Request $request, $id): TestResource
    {
        return new TestResource([]);
    }

    public function showTyped(int $id, bool $active): TestResource
    {
        return new TestResource([]);
    }

    public function showModel(TestModel $model): TestResource
    {
        return new TestResource([]);
    }

    public function noTypeHint($request)
    {
        return response()->json(['ok' => true]);
    }
}

class TestModel extends Model {}

class TestGetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'search' => 'sometimes|string',
            'page' => 'integer',
        ];
    }
}

class TestPostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email',
            'age' => 'integer',
            'is_active' => 'boolean',
            'tags' => 'array',
            'type' => 'in:admin,user',
            'user_id' => 'uuid',
        ];
    }

    public function queryRules(): array
    {
        return [
            'notify' => 'boolean',
        ];
    }
}

class TestResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

class BrokenRulesRequest extends FormRequest
{
    public function rules(): array
    {
        // This will fail because route() is null in unit tests unless mocked
        $this->route()->named('fail');
        return [];
    }
}

/**
 * Resource whose toArray() carries a full array-shape PHPDoc annotation so that
 * the phpdoc-parser-based extraction path is exercised.
 */
class TypedTestResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, score: float, is_active: bool, tags: array, note: string|null, nickname?: string}
     */
    public function toArray($request): array
    {
        return [
            'id'        => 1,
            'name'      => 'Alice',
            'score'     => 9.5,
            'is_active' => true,
            'tags'      => [],
            'note'      => null,
            'nickname'  => 'ali',
        ];
    }
}

/**
 * Resource with a generic array-shape annotation using quoted string keys.
 */
class QuotedKeyResource extends JsonResource
{
    /**
     * @return array{'user_id': int, 'display_name': string}
     */
    public function toArray($request): array
    {
        return ['user_id' => 1, 'display_name' => 'Alice'];
    }
}
