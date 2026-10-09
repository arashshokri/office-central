<?php

namespace Tests\Feature;

use App\Support\PanelDate;
use Tests\TestCase;

class PanelDateTest extends TestCase
{
    public function test_panel_dates_use_jalali_and_tehran_timezone(): void
    {
        $this->assertSame('۱۴۰۵/۰۷/۱۷ ۱۳:۲۰', PanelDate::format('2026-10-09T09:50:00Z'));
        $this->assertSame('۱۴۰۴/۰۱/۰۱', PanelDate::format('2025-03-21', 'Y/m/d'));
        $this->assertSame('۱۴۰۳/۱۲/۳۰', PanelDate::format('2025-03-20', 'Y/m/d'));
        $this->assertSame('۱۴۰۵/۰۷/۱۸', PanelDate::format('2026-10-09T23:30:00Z', 'Y/m/d'));
        $this->assertSame('—', PanelDate::format(null));
        $this->assertSame('—', PanelDate::format('invalid-date'));
    }

    public function test_picker_keeps_iso_submission_and_jalali_presentation(): void
    {
        $html = view('components.jalali-picker', ['name' => 'expires_at', 'label' => 'انقضا', 'value' => '2026-10-09'])->render();
        $this->assertStringContainsString('۱۴۰۵/۰۷/۱۷', $html);
        $this->assertStringContainsString('value="2026-10-09"', $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }
}
