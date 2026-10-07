<?php
namespace App\Enums;
enum InstallationStatus: string { case Pending='pending'; case Active='active'; case Locked='locked'; case Inactive='inactive'; }
