<?php
namespace App\Enums;
enum SecurityEventType: string { case CloneDetected='clone_detected'; case HardwareMismatch='hardware_mismatch'; case ReplayDetected='replay_detected'; case InvalidToken='invalid_token'; case RateLimited='rate_limited'; }
