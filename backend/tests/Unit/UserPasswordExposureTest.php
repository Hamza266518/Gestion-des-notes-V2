<?php

namespace Tests\Unit;

use App\Models\User;
use ReflectionProperty;
use Tests\TestCase;

class UserPasswordExposureTest extends TestCase
{
    private function appends(User $user): array
    {
        $property = new ReflectionProperty($user, 'appends');
        $property->setAccessible(true);

        return $property->getValue($user) ?: [];
    }

    public function test_plain_password_is_not_appended_globally(): void
    {
        $this->assertNotContains('password_plain', $this->appends(new User()));
    }

    public function test_password_material_stays_hidden(): void
    {
        $hidden = (new User())->getHidden();

        $this->assertContains('password', $hidden);
        $this->assertContains('password_encrypted', $hidden);
        $this->assertContains('password_original_encrypted', $hidden);
        $this->assertContains('remember_token', $hidden);
    }

    public function test_plain_password_is_revealed_only_when_explicitly_requested(): void
    {
        $user = new User();
        $user->withPlainPassword();

        $this->assertContains('password_plain', $this->appends($user));
    }

    public function test_plain_password_is_not_serialized_by_default(): void
    {
        $serialized = (new User(['name' => 'Test']))->toArray();

        $this->assertArrayNotHasKey('password_plain', $serialized);
        $this->assertArrayNotHasKey('password_encrypted', $serialized);
        $this->assertArrayNotHasKey('password_original_encrypted', $serialized);
    }
}
