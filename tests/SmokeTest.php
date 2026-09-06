<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SmokeTest extends WebTestCase
{
    #[DataProvider('publicUrlProvider')]
    public function testPageIsSuccessful(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful(sprintf('URL %s should return 2xx', $url));
    }

    /**
     * @return \Generator<array{0: string}>
     */
    public static function publicUrlProvider(): \Generator
    {
        yield ['/'];
        yield ['/links'];
        yield ['/collections'];
        yield ['/collections/new'];
        yield ['/import'];
    }

    #[DataProvider('apiUrlProvider')]
    public function testApiWithBearer(string $method, string $url): void
    {
        $client = static::createClient();
        $client->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer testtoken']);
        self::assertResponseIsSuccessful(sprintf('%s %s should return 2xx', $method, $url));
    }

    /**
     * @return \Generator<array{0: string, 1: string}>
     */
    public static function apiUrlProvider(): \Generator
    {
        yield ['GET', '/api/v1/users/me'];
        yield ['GET', '/api/v1/collections'];
        yield ['GET', '/api/v1/tags'];
        yield ['GET', '/api/v1/links'];
    }
}
