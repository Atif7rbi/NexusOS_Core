<?php
declare(strict_types=1);
namespace App\Modules\Shared\Phone;
use InvalidArgumentException;
final class InvalidSaudiMobileNumberException extends InvalidArgumentException
{
    public const REASON_REQUIRED = 'required';
    public const REASON_INVALID_TYPE = 'invalid_type';
    public const REASON_INVALID_FORMAT = 'invalid_format';
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
