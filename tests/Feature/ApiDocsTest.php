<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Документация API: страница /docs и схема /openapi.json описывают каждый адрес API. */
class ApiDocsTest extends TestCase
{
    /** Страницы интерфейса и служебные адреса, которые не являются частью API. */
    private const NOT_API = ['/', '/customer.html', '/lead.html', '/offer.html', '/shell-files', '/docs', '/openapi.json', '/up'];

    public function test_docs_page_and_schema_are_served(): void
    {
        $this->get('/docs')->assertOk()->assertSee('/swagger/swagger-ui-bundle.js', false);
        $spec = $this->getJson('/openapi.json')->assertOk()->json();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(config('session.cookie'), $spec['components']['securitySchemes']['cookieAuth']['name']);
    }

    public function test_every_api_route_is_documented(): void
    {
        $spec = $this->getJson('/openapi.json')->json('paths');
        $documented = [];
        foreach ($spec as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path)] = true;
            }
        }
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (in_array($uri, self::NOT_API, true) || str_starts_with($uri, '/_') || str_starts_with($uri, '/storage')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $uri);
                if (! isset($documented[$key])) {
                    $missing[] = $key;
                }
            }
        }
        $this->assertSame([], $missing, 'Нет описания в документации API (app/Support/ApiDocs.php)');
    }
}
