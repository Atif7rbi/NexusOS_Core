<?php

declare(strict_types=1);

namespace App\Modules\Entitlements\Services;

use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use InvalidArgumentException;

final class ValidatePlanModuleDependencies
{
    public function __construct(
        private readonly CommercialModuleCatalog $catalog,
    ) {
    }

    /** @param list<string> $moduleKeys */
    public function handle(array $moduleKeys): void
    {
        $selected = array_fill_keys(array_values(array_unique($moduleKeys)), true);

        foreach ($selected as $moduleKey => $_enabled) {
            $definition = $this->catalog->definitions()[$moduleKey] ?? null;

            if ($definition === null) {
                throw new InvalidArgumentException(
                    "Unknown commercial module [{$moduleKey}].",
                );
            }

            $missing = array_values(array_filter(
                $definition['dependencies'],
                static fn (string $dependency): bool => ! isset($selected[$dependency]),
            ));

            if ($missing !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Module [%s] requires: %s.',
                    $moduleKey,
                    implode(', ', $missing),
                ));
            }
        }
    }
}
