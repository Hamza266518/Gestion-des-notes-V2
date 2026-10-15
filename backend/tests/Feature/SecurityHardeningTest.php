<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    private function middlewareFor(string $method, string $uri): array
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => in_array($method, $r->methods(), true) && str_ends_with($r->uri(), $uri));

        $this->assertNotNull($route, "Route {$method} {$uri} introuvable");

        return $route->gatherMiddleware();
    }

    public function test_password_recovery_endpoints_are_rate_limited(): void
    {
        $this->assertContains('throttle:6,1', $this->middlewareFor('POST', 'auth/password/forgot'));
        $this->assertContains('throttle:5,1', $this->middlewareFor('POST', 'auth/password/reset'));
    }

    public function test_account_endpoints_are_rate_limited(): void
    {
        $this->assertContains('auth:sanctum', $this->middlewareFor('GET', 'account'));
        $this->assertContains('throttle:6,1', $this->middlewareFor('PUT', 'account/email'));
        $this->assertContains('throttle:8,1', $this->middlewareFor('POST', 'account/email/verify'));
        $this->assertContains('throttle:5,1', $this->middlewareFor('POST', 'account/password'));
    }

    public function test_legacy_forgot_password_routes_are_gone(): void
    {
        foreach (['etudiant/password/forgot-verify', 'etudiant/password/reset'] as $legacy) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => in_array('POST', $r->methods(), true) && str_ends_with($r->uri(), $legacy));

            $this->assertNull($route, "La route obsolète {$legacy} doit être supprimée");
        }
    }

    public function test_admin_routes_require_authentication_and_admin_role(): void
    {
        $middleware = $this->middlewareFor('GET', 'admin/etudiants');

        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains(\App\Http\Middleware\IsAdmin::class, $middleware);
    }

    public function test_gemini_service_does_not_disable_tls_verification(): void
    {
        $source = file_get_contents(app_path('Services/GeminiService.php'));

        $this->assertStringNotContainsString('withoutVerifying', $source);
    }

    public function test_gemini_service_does_not_log_raw_payloads(): void
    {
        $source = file_get_contents(app_path('Services/GeminiService.php'));

        foreach (['raw response', 'raw_text', "'body' =>", 'getTraceAsString'] as $leak) {
            $this->assertStringNotContainsString($leak, $source, "Fuite de données potentielle: {$leak}");
        }
    }

    public function test_gemini_transport_failure_is_reported_as_connectivity_error(): void
    {
        $service = new \App\Services\GeminiService();

        $baseUrl = new \ReflectionProperty($service, 'baseUrl');
        $baseUrl->setAccessible(true);
        $baseUrl->setValue($service, 'https://127.0.0.1:9/v1/model:generateContent');

        $this->expectException(\App\Exceptions\GeminiTransportException::class);

        $service->scanExamens([base64_encode('%PDF-1.4')]);
    }
}
