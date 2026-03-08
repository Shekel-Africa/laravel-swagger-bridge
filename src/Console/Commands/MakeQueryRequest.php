<?php

namespace Shekel\SwaggerBridge\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeQueryRequest extends Command
{
    protected $signature = 'make:query-request
                            {name : Class name, optionally with subdirectory (e.g. Users/ListUsersRequest)}';

    protected $description = 'Create a new QueryRequest class for GET query-parameter validation and Swagger schema generation';

    public function __construct(private Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->argument('name');

        $path      = $this->resolveFilePath($name);
        $namespace = $this->resolveNamespace($name);
        $className = Str::studly(class_basename(str_replace('/', '\\', $name)));

        if ($this->files->exists($path)) {
            $this->error("QueryRequest [{$path}] already exists.");
            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->buildContents($namespace, $className));

        $this->info("QueryRequest [{$path}] created successfully.");

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function resolveFilePath(string $name): string
    {
        $relativePath = str_replace('\\', '/', Str::studly(str_replace('/', '\\', $name)));

        return app_path("Http/Requests/{$relativePath}.php");
    }

    private function resolveNamespace(string $name): string
    {
        $rootNamespace = rtrim(app()->getNamespace(), '\\');
        $base          = $rootNamespace . '\\Http\\Requests';

        // Everything except the final class segment becomes extra namespace parts.
        $parts = explode('/', str_replace('\\', '/', $name));
        array_pop($parts); // remove class name

        if (empty($parts)) {
            return $base;
        }

        return $base . '\\' . implode('\\', array_map([Str::class, 'studly'], $parts));
    }

    private function buildContents(string $namespace, string $className): string
    {
        $stub = $this->files->get($this->resolveStubPath());

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $className],
            $stub
        );
    }

    private function resolveStubPath(): string
    {
        // Allow the host application to publish and customise the stub.
        $published = resource_path('stubs/swagger-bridge/query-request.stub');

        return file_exists($published)
            ? $published
            : __DIR__ . '/../../../stubs/query-request.stub';
    }
}
