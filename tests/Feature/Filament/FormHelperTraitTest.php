<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Tests\TestCase;

class FormHelperTraitTest extends TestCase
{
    public function test_make_settings_section_returns_a_group_when_flat(): void
    {
        $component = AppSettingsPage::makeSettingsSection('Example', 'root', 'sub', [], 'desc', flat: true);

        $this->assertInstanceOf(Group::class, $component);
    }

    public function test_make_settings_section_returns_a_section_by_default(): void
    {
        $component = AppSettingsPage::makeSettingsSection('Example', 'root', 'sub', [], 'desc');

        $this->assertInstanceOf(Section::class, $component);
    }
}
