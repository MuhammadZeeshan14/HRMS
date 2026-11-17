<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        TrustProxies::flushState();
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    public function test_login_uses_https_urls_behind_a_configured_proxy(): void
    {
        $this->configureProxies('*');

        $this->proxyRequest()
            ->assertOk()
            ->assertSee('action="https://hrms.example.test/login"', false)
            ->assertSee('href="https://hrms.example.test/hrms.css"', false);
    }

    public function test_forwarded_scheme_is_ignored_without_a_trusted_proxy(): void
    {
        $this->configureProxies('');

        $this->proxyRequest()
            ->assertOk()
            ->assertSee('action="http://hrms.example.test/login"', false);
    }

    private function configureProxies(string $proxies): void
    {
        putenv('TRUSTED_PROXIES='.$proxies);
        $_ENV['TRUSTED_PROXIES'] = $proxies;
        $_SERVER['TRUSTED_PROXIES'] = $proxies;
        $this->refreshApplication();
    }

    private function proxyRequest()
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('http://hrms.example.test/login');
    }
}
