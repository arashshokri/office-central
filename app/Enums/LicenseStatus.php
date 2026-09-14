<?php
namespace App\Enums;
enum LicenseStatus: string { case Created='created'; case Active='active'; case Suspended='suspended'; case Expired='expired'; case Revoked='revoked'; }
