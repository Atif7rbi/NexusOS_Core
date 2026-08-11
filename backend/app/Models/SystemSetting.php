<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SystemSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'company_name_ar',
        'company_name_en',
        'short_name_ar',
        'short_name_en',
        'company_tagline_ar',
        'company_tagline_en',
        'logo_path',
        'favicon_path',
        'primary_color',
        'secondary_color',
        'language',
        'timezone',
        'currency',
        'date_format',
        'phone',
        'email',
        'website',
        'address',
        'commercial_registration',
        'vat_number',
    ];

    public static function forTenant(string $tenantId): self
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return static::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            self::defaultsFor($tenant),
        );
    }

    /**
     * @return array<string, string|null>
     */
    private static function defaultsFor(Tenant $tenant): array
    {
        return [
            'company_name_ar' => $tenant->name,
            'company_name_en' => null,
            'short_name_ar' => Str::limit($tenant->name, 100, ''),
            'short_name_en' => null,
            'company_tagline_ar' => null,
            'company_tagline_en' => null,
            'primary_color' => '#0f172a',
            'secondary_color' => '#475569',
            'language' => $tenant->locale,
            'timezone' => $tenant->timezone,
            'currency' => $tenant->currency,
            'date_format' => 'Y-m-d',
        ];
    }
}
