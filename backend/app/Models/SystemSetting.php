<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    private const DEFAULTS = [
        'company_name_ar' => 'شركة أفق السكنية',
        'company_name_en' => 'Ufq Housing Company',
        'short_name_ar' => 'أفق',
        'short_name_en' => 'Ufq',
        'company_tagline_ar' => 'للتطوير العقاري',
        'company_tagline_en' => 'Real Estate Development',
        'primary_color' => '#0f172a',
        'secondary_color' => '#475569',
        'language' => 'ar',
        'timezone' => 'Asia/Riyadh',
        'currency' => 'SAR',
        'date_format' => 'Y-m-d',
    ];

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
        return static::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            self::DEFAULTS,
        );
    }
}
