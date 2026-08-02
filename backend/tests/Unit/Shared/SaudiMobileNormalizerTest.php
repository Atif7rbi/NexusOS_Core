<?php
declare(strict_types=1);
namespace Tests\Unit\Shared;
use App\Modules\Shared\Phone\InvalidSaudiMobileNumberException;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use PHPUnit\Framework\TestCase;
final class SaudiMobileNormalizerTest extends TestCase
{
    public function test_matrix(): void
    {
        $n = new SaudiMobileNormalizer();
        foreach (['0501234567', "\t0501234567 ", '٠٥٠١٢٣٤٥٦٧', '۰۵۰۱۲۳۴۵۶۷'] as $input) self::assertSame('0501234567', $n->normalizeRequired($input));
        self::assertNull($n->normalizeNullable(null));
        self::assertNull($n->normalizeNullable(" \t\r\n\v\f"));
        foreach (["\u{00A0}0501234567", "\0".'0501234567', '050 123 4567', '+966501234567', '966501234567', '00966501234567', '051234567'] as $input) {
            try { $n->normalizeRequired($input); self::fail('Expected rejection.'); }
            catch (InvalidSaudiMobileNumberException $e) { self::assertSame('invalid_format', $e->reason); self::assertStringNotContainsString($input, $e->getMessage()); }
        }
    }
    public function test_required_and_type_errors(): void
    {
        $n = new SaudiMobileNormalizer();
        foreach ([null, '', " \t\n"] as $input) {
            try { $n->normalizeRequired($input); self::fail('Expected required.'); }
            catch (InvalidSaudiMobileNumberException $e) { self::assertSame('required', $e->reason); }
        }
        try { $n->normalizeNullable(123); self::fail('Expected type error.'); }
        catch (InvalidSaudiMobileNumberException $e) { self::assertSame('invalid_type', $e->reason); }
    }
}
