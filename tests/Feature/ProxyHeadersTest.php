<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProxyHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/proxy-context', fn (Request $request) => response()->json([
            'secure' => $request->secure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'ip' => $request->ip(),
            'url' => url('/login'),
        ]));
    }

    public function test_https_and_client_ip_are_preserved_through_the_private_web_proxy(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.29.87.20', 'HTTPS' => 'off', 'SERVER_PORT' => 80])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'central.example.com',
                'X-Forwarded-Port' => '443',
                // NPM appends the actual client after any client-supplied XFF.
                'X-Forwarded-For' => '198.51.100.55, 203.0.113.7',
            ])->get('http://localhost/proxy-context')->assertOk()->assertExactJson([
                'secure' => true,
                'host' => 'central.example.com',
                'port' => 443,
                'ip' => '203.0.113.7',
                'url' => 'https://central.example.com/login',
            ]);
    }

    public function test_http_preview_is_not_marked_as_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.29.87.20', 'HTTPS' => 'off', 'SERVER_PORT' => 80])
            ->withHeaders([
                'X-Forwarded-Proto' => 'http',
                'X-Forwarded-Host' => 'central.example.com',
                'X-Forwarded-Port' => '80',
            ])->get('http://localhost/proxy-context')->assertOk()
            ->assertJsonPath('secure', false)
            ->assertJsonPath('url', 'http://central.example.com/login');
    }

    public function test_untrusted_source_cannot_forge_https_or_the_client_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9', 'HTTPS' => 'off', 'SERVER_PORT' => 80])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'spoofed.example.com',
                'X-Forwarded-For' => '203.0.113.7',
            ])->get('http://localhost/proxy-context')->assertOk()
            ->assertJsonPath('secure', false)
            ->assertJsonPath('ip', '198.51.100.9');
    }
}
