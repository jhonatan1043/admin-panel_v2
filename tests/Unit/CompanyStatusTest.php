<?php

namespace Tests\Unit;

use App\Models\Company;
use Tests\TestCase;

class CompanyStatusTest extends TestCase
{
    private function statusAt(int $minutes): string
    {
        return (new Company(['last_activity_at' => now()->subMinutes($minutes)]))->current_status;
    }

    public function test_el_estado_se_calcula_por_tiempo(): void
    {
        $this->assertSame('active', $this->statusAt(5));      // < 10 min
        $this->assertSame('pending', $this->statusAt(15));    // 10-29
        $this->assertSame('offline', $this->statusAt(45));    // 30-59
        $this->assertSame('deletable', $this->statusAt(90));  // 60+
    }
}
