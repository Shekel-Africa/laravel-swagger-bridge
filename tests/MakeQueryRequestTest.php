<?php

namespace Shekel\SwaggerBridge\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class MakeQueryRequestTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = app_path('Http/Requests');
    }

    protected function tearDown(): void
    {
        // Clean up any generated files after each test.
        File::deleteDirectory($this->basePath);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Happy-path generation
    // ------------------------------------------------------------------

    public function test_it_creates_file_in_requests_directory()
    {
        $exitCode = Artisan::call('make:query-request', ['name' => 'ListUsersRequest']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->basePath . '/ListUsersRequest.php');
    }

    public function test_generated_file_contains_correct_class_name()
    {
        Artisan::call('make:query-request', ['name' => 'ListUsersRequest']);

        $contents = File::get($this->basePath . '/ListUsersRequest.php');
        $this->assertStringContainsString('class ListUsersRequest extends QueryRequest', $contents);
    }

    public function test_generated_file_contains_correct_namespace()
    {
        Artisan::call('make:query-request', ['name' => 'ListUsersRequest']);

        $contents = File::get($this->basePath . '/ListUsersRequest.php');
        $this->assertStringContainsString('namespace App\\Http\\Requests;', $contents);
    }

    public function test_generated_file_contains_query_rules_method()
    {
        Artisan::call('make:query-request', ['name' => 'ListUsersRequest']);

        $contents = File::get($this->basePath . '/ListUsersRequest.php');
        $this->assertStringContainsString('public function queryRules(): array', $contents);
    }

    public function test_generated_file_imports_query_request()
    {
        Artisan::call('make:query-request', ['name' => 'ListUsersRequest']);

        $contents = File::get($this->basePath . '/ListUsersRequest.php');
        $this->assertStringContainsString('use Shekel\\SwaggerBridge\\Http\\Requests\\QueryRequest;', $contents);
    }

    // ------------------------------------------------------------------
    // Subdirectory / nested names
    // ------------------------------------------------------------------

    public function test_it_creates_subdirectory_for_nested_names()
    {
        Artisan::call('make:query-request', ['name' => 'Users/ListUsersRequest']);

        $this->assertFileExists($this->basePath . '/Users/ListUsersRequest.php');
    }

    public function test_nested_file_has_correct_namespace()
    {
        Artisan::call('make:query-request', ['name' => 'Users/ListUsersRequest']);

        $contents = File::get($this->basePath . '/Users/ListUsersRequest.php');
        $this->assertStringContainsString('namespace App\\Http\\Requests\\Users;', $contents);
    }

    public function test_nested_file_has_correct_class_name()
    {
        Artisan::call('make:query-request', ['name' => 'Users/ListUsersRequest']);

        $contents = File::get($this->basePath . '/Users/ListUsersRequest.php');
        $this->assertStringContainsString('class ListUsersRequest extends QueryRequest', $contents);
    }

    // ------------------------------------------------------------------
    // Duplicate protection
    // ------------------------------------------------------------------

    public function test_it_returns_failure_when_file_already_exists()
    {
        Artisan::call('make:query-request', ['name' => 'ListUsersRequest']);
        $exitCode = Artisan::call('make:query-request', ['name' => 'ListUsersRequest']);

        $this->assertSame(1, $exitCode);
    }
}
