<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiV1OpenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_the_description_is_public_valid_json_and_headers_are_the_api_ones(): void
    {
        $response = $this->get('/api/v1/openapi.json')->assertOk();
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('1', $response->headers->get('X-Perysee-Api-Version'));
        $this->assertSame([], $response->headers->getCookies());
        $document = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('3.1', $document['openapi']);
        $this->assertSame('1', $document['info']['version']);
        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes']);
        $this->assertArrayHasKey('Error', $document['components']['schemas']);
    }

    public function test_every_route_of_the_api_is_described_and_nothing_else(): void
    {
        $document = json_decode($this->get('/api/v1/openapi.json')->getContent(), true);
        $described = [];
        foreach ($document['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $described[] = strtoupper($method).' '.$path;
            }
        }
        $actual = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                $actual[] = 'GET /'.preg_replace('#^api/v1/#', '', $route->uri());
            }
        }
        $normalise = fn (array $paths): array => array_values(array_unique(array_map(fn (string $line): string => str_replace('GET //', 'GET /', preg_replace('#\{[a-z]+\}#', '{}', $line)), $paths)));
        $described = $normalise($described);
        $actual = $normalise($actual);
        sort($described);
        sort($actual);
        $this->assertSame($actual, $described);
    }

    public function test_the_description_lists_exactly_the_scopes_the_application_knows(): void
    {
        $document = json_decode($this->get('/api/v1/openapi.json')->getContent(), true);
        $this->assertSame(array_keys(\App\Analytics\Reporting\ReportSections::SCOPES), $document['components']['securitySchemes']['bearerAuth']['x-scopes']);
        $dimension = collect($document['paths']['/dimensions/{dimension}']['get']['parameters'])->firstWhere('name', 'dimension');
        $this->assertSame(['path', 'source', 'campaign', 'device', 'device_brand', 'browser', 'country', 'event'], $dimension['schema']['enum']);
    }
}
