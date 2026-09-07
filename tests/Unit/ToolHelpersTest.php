<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Tools\ToolHelpers;

/**
 * Unit tests for the ToolHelpers trait.
 *
 * Uses an anonymous class to exercise the trait in isolation —
 * no database or WP bootstrap required.
 */
class ToolHelpersTest extends TestCase {

    /** @var object Anonymous class using ToolHelpers */
    private object $subject;

    protected function setUp(): void {
        parent::setUp();

        $this->subject = new class {
            use ToolHelpers;

            // Expose protected methods as public for testing.
            public function publicSanitizeDate(?string $v): ?string {
                return $this->sanitize_date($v);
            }

            public function publicCastColumns(array $rows, array $ints = [], array $floats = []): array {
                return $this->cast_columns($rows, $ints, $floats);
            }
        };
    }

    // -----------------------------------------------------------------------
    // sanitize_date()
    // -----------------------------------------------------------------------

    public function test_valid_date_is_returned_unchanged(): void {
        $this->assertSame('2024-01-15', $this->subject->publicSanitizeDate('2024-01-15'));
    }

    public function test_null_input_returns_null(): void {
        $this->assertNull($this->subject->publicSanitizeDate(null));
    }

    public function test_empty_string_returns_null(): void {
        $this->assertNull($this->subject->publicSanitizeDate(''));
    }

    public function test_invalid_format_returns_null(): void {
        $this->assertNull($this->subject->publicSanitizeDate('15-01-2024'));
        $this->assertNull($this->subject->publicSanitizeDate('2024/01/15'));
        $this->assertNull($this->subject->publicSanitizeDate('not-a-date'));
    }

    public function test_invalid_date_values_return_null(): void {
        // Month 13 does not exist.
        $this->assertNull($this->subject->publicSanitizeDate('2024-13-01'));
        // Day 32 does not exist.
        $this->assertNull($this->subject->publicSanitizeDate('2024-01-32'));
    }

    public function test_leap_day_on_leap_year_is_valid(): void {
        $this->assertSame('2024-02-29', $this->subject->publicSanitizeDate('2024-02-29'));
    }

    public function test_leap_day_on_non_leap_year_returns_null(): void {
        $this->assertNull($this->subject->publicSanitizeDate('2023-02-29'));
    }

    // -----------------------------------------------------------------------
    // cast_columns()
    // -----------------------------------------------------------------------

    public function test_integer_columns_are_cast(): void {
        $rows = [['orders' => '42', 'revenue' => '1234.56']];

        $result = $this->subject->publicCastColumns($rows, ints: ['orders']);

        $this->assertSame(42, $result[0]['orders']);
        $this->assertSame('1234.56', $result[0]['revenue']); // unchanged
    }

    public function test_float_columns_are_cast(): void {
        $rows = [['orders' => '10', 'revenue' => '9999.99']];

        $result = $this->subject->publicCastColumns($rows, floats: ['revenue']);

        $this->assertSame(9999.99, $result[0]['revenue']);
        $this->assertSame('10', $result[0]['orders']); // unchanged
    }

    public function test_both_types_cast_simultaneously(): void {
        $rows = [['total_orders' => '5', 'gross_revenue' => '250.75', 'avg_order_value' => '50.15']];

        $result = $this->subject->publicCastColumns(
            $rows,
            ints:   ['total_orders'],
            floats: ['gross_revenue', 'avg_order_value']
        );

        $this->assertSame(5,      $result[0]['total_orders']);
        $this->assertSame(250.75, $result[0]['gross_revenue']);
        $this->assertSame(50.15,  $result[0]['avg_order_value']);
    }

    public function test_missing_column_is_skipped_gracefully(): void {
        $rows = [['orders' => '3']];

        // 'revenue' does not exist in the row — should not throw.
        $result = $this->subject->publicCastColumns($rows, floats: ['revenue']);

        $this->assertSame('3', $result[0]['orders']);
    }

    public function test_empty_rows_returns_empty_array(): void {
        $this->assertSame([], $this->subject->publicCastColumns([], ints: ['orders']));
    }

    public function test_multiple_rows_all_cast(): void {
        $rows = [
            ['orders' => '1', 'revenue' => '100.00'],
            ['orders' => '2', 'revenue' => '200.50'],
        ];

        $result = $this->subject->publicCastColumns($rows, ints: ['orders'], floats: ['revenue']);

        $this->assertSame(1,      $result[0]['orders']);
        $this->assertSame(100.0,  $result[0]['revenue']);
        $this->assertSame(2,      $result[1]['orders']);
        $this->assertSame(200.50, $result[1]['revenue']);
    }
}
