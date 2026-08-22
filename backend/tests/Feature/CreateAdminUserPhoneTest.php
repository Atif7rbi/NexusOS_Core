<?php
declare(strict_types=1);
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class CreateAdminUserPhoneTest extends TestCase
{
    use RefreshDatabase;
    public function test_command_canonicalizes_phone(): void
    {
        $this->artisan('nexusos:create-admin')->expectsQuestion('الاسم الكامل','مدير')->expectsQuestion('البريد الإلكتروني','phone-admin@example.com')->expectsQuestion('رقم الجوال (اختياري)',"\t٠٥٠١٢٣٤٥٦٧ ")->expectsQuestion('كلمة المرور','StrongPassword8!')->expectsQuestion('تأكيد كلمة المرور','StrongPassword8!')->assertSuccessful();
        $this->assertDatabaseHas('users',['email'=>'phone-admin@example.com','phone'=>'0501234567']);
    }
}
