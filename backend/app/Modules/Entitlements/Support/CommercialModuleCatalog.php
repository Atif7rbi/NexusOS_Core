<?php

declare(strict_types=1);

namespace App\Modules\Entitlements\Support;

final class CommercialModuleCatalog
{
    public const PROJECTS = 'projects';

    public const UNITS = 'units';

    public const CUSTOMERS = 'customers';

    public const CRM = 'crm';

    public const RESERVATIONS = 'reservations';

    public const CONTRACTS = 'contracts';

    public const COLLECTIONS = 'collections';

    public const PILOT_FULL_PLAN = 'pilot_full';

    public const DEMO_FULL_PLAN = 'demo_full';

    /**
     * @return array<string, array{name_ar: string, name_en: string, dependencies: list<string>}>
     */
    public function definitions(): array
    {
        return [
            self::PROJECTS => [
                'name_ar' => 'المشاريع',
                'name_en' => 'Projects',
                'dependencies' => [],
            ],
            self::UNITS => [
                'name_ar' => 'الوحدات',
                'name_en' => 'Units',
                'dependencies' => [self::PROJECTS],
            ],
            self::CUSTOMERS => [
                'name_ar' => 'العملاء',
                'name_en' => 'Customers',
                'dependencies' => [],
            ],
            self::CRM => [
                'name_ar' => 'إدارة العملاء المحتملين',
                'name_en' => 'CRM',
                'dependencies' => [self::CUSTOMERS],
            ],
            self::RESERVATIONS => [
                'name_ar' => 'الحجوزات',
                'name_en' => 'Reservations',
                'dependencies' => [
                    self::PROJECTS,
                    self::UNITS,
                    self::CUSTOMERS,
                ],
            ],
            self::CONTRACTS => [
                'name_ar' => 'العقود',
                'name_en' => 'Contracts',
                'dependencies' => [self::RESERVATIONS],
            ],
            self::COLLECTIONS => [
                'name_ar' => 'الأقساط والتحصيلات',
                'name_en' => 'Collections',
                'dependencies' => [self::CONTRACTS],
            ],
        ];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    public function contains(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }
}
