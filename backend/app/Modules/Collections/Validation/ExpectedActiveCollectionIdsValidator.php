<?php

declare(strict_types=1);

namespace App\Modules\Collections\Validation;

use App\Modules\Collections\Exceptions\InvalidExpectedActiveCollectionIdsException;
use Symfony\Component\Uid\Ulid;

/**
 * Performs structural validation and canonicalization for the active
 * Collection IDs supplied as an Amendment generation token.
 *
 * This validator is deliberately database-agnostic. Whether the canonical
 * set matches the currently locked active schedule is decided inside
 * AmendCollectionScheduleAction.
 */
final class ExpectedActiveCollectionIdsValidator
{
    /**
     * @param  array<mixed>  $ids
     * @return array<int, string>
     */
    public function validateAndCanonicalize(array $ids): array
    {
        if ($ids === [] || ! array_is_list($ids)) {
            throw new InvalidExpectedActiveCollectionIdsException;
        }

        $canonical = [];

        foreach ($ids as $id) {
            if (! is_string($id) || ! Ulid::isValid($id)) {
                throw new InvalidExpectedActiveCollectionIdsException;
            }

            $canonical[] = strtoupper($id);
        }

        if (count(array_unique($canonical)) !== count($canonical)) {
            throw new InvalidExpectedActiveCollectionIdsException;
        }

        sort($canonical, SORT_STRING);

        return $canonical;
    }
}
