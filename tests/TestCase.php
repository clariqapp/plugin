<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for all unit tests.
 *
 * Wires Brain\Monkey (WP function mocks) and Mockery before each test
 * and tears them down cleanly after.
 */
abstract class TestCase extends PHPUnitTestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub the most commonly used WP functions so tests don't need
        // to mock them individually in every test method.
        Monkey\Functions\stubs([
            'get_option'     => null,
            'update_option'  => true,
            'delete_option'  => true,
            'home_url'       => 'https://example.com',
            'esc_html'       => fn (string $s) => $s,
            'esc_html__'     => fn (string $s) => $s,
            '__'             => fn (string $s) => $s,
        ]);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}
