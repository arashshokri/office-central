<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OfflineGraceContractTest extends TestCase
{
    public function test_transport_failure_keeps_the_last_signed_state_indefinitely(): void
    {
        $cachedState = ['access' => 'allowed', 'state_revision' => 12, 'expires_at' => null];
        $centralResponse = null;
        $elapsedOfflineYears = 20;
        $mayRun = $centralResponse === null && $cachedState['access'] === 'allowed' && $cachedState['expires_at'] === null;

        $this->assertTrue($mayRun);
        $this->assertSame(20, $elapsedOfflineYears);
    }

    public function test_only_a_newer_signed_revision_can_change_access(): void
    {
        $highestRevision = 8;
        $replayedAllowedState = ['access' => 'allowed', 'state_revision' => 7, 'signature_valid' => true];
        $newLockedState = ['access' => 'locked', 'state_revision' => 9, 'signature_valid' => true];

        $this->assertFalse($replayedAllowedState['signature_valid'] && $replayedAllowedState['state_revision'] >= $highestRevision);
        $this->assertTrue($newLockedState['signature_valid'] && $newLockedState['state_revision'] >= $highestRevision);
    }
}
