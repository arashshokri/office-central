<?php
namespace App\Enums;
enum LockReason: string { case Admin='admin'; case Clone='clone'; case Security='security'; }
