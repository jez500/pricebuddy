<?php

namespace Tests\Unit\Enums;

use App\Enums\Role;
use Filament\Support\Contracts\HasLabel;
use Tests\TestCase;

class RoleTest extends TestCase
{
    public function test_it_has_admin_and_user_cases_with_string_values()
    {
        $this->assertSame('admin', Role::Admin->value);
        $this->assertSame('user', Role::User->value);
    }

    public function test_it_implements_has_label_returning_case_name()
    {
        $this->assertInstanceOf(HasLabel::class, Role::Admin);
        $this->assertSame('Admin', Role::Admin->getLabel());
        $this->assertSame('User', Role::User->getLabel());
    }
}
