<?php
namespace App\Enums;
enum ReleaseStatus: string { case Draft='draft'; case Published='published'; case Deprecated='deprecated'; case Disabled='disabled'; }
