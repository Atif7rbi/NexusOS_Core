<?php

declare(strict_types=1);

namespace Tests\Unit\Collections;

use App\Modules\Collections\Exceptions\InvalidExpectedActiveCollectionIdsException;
use App\Modules\Collections\Validation\ExpectedActiveCollectionIdsValidator;
use PHPUnit\Framework\TestCase;

final class ExpectedActiveCollectionIdsValidatorTest extends TestCase
{
    private const FIRST_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const SECOND_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    private ExpectedActiveCollectionIdsValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ExpectedActiveCollectionIdsValidator;
    }

    public function test_empty_array_is_rejected(): void
    {
        $this->expectException(InvalidExpectedActiveCollectionIdsException::class);

        $this->validator->validateAndCanonicalize([]);
    }

    public function test_associative_array_is_rejected(): void
    {
        $this->expectException(InvalidExpectedActiveCollectionIdsException::class);

        $this->validator->validateAndCanonicalize(['first' => self::FIRST_ULID]);
    }

    public function test_non_string_element_is_rejected(): void
    {
        $this->expectException(InvalidExpectedActiveCollectionIdsException::class);

        $this->validator->validateAndCanonicalize([123]);
    }

    public function test_invalid_ulid_is_rejected(): void
    {
        $this->expectException(InvalidExpectedActiveCollectionIdsException::class);

        $this->validator->validateAndCanonicalize(['not-a-ulid']);
    }

    public function test_duplicate_ids_after_case_canonicalization_are_rejected(): void
    {
        $this->expectException(InvalidExpectedActiveCollectionIdsException::class);

        $this->validator->validateAndCanonicalize([
            self::FIRST_ULID,
            strtolower(self::FIRST_ULID),
        ]);
    }

    public function test_valid_ids_are_returned_uppercase_and_sorted(): void
    {
        $result = $this->validator->validateAndCanonicalize([
            strtolower(self::SECOND_ULID),
            strtolower(self::FIRST_ULID),
        ]);

        $this->assertSame([self::FIRST_ULID, self::SECOND_ULID], $result);
    }

    public function test_lowercase_valid_ulid_is_accepted(): void
    {
        $result = $this->validator->validateAndCanonicalize([
            strtolower(self::FIRST_ULID),
        ]);

        $this->assertSame([self::FIRST_ULID], $result);
    }

    public function test_whitespace_padded_ulid_is_rejected(): void
    {
        $this->expectException(InvalidExpectedActiveCollectionIdsException::class);

        $this->validator->validateAndCanonicalize(['  '.self::FIRST_ULID.'  ']);
    }
}
