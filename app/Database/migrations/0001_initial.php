<?php
declare(strict_types=1);
defined('NX_ROOT') || exit;

// v1.0.0 baseline. The full schema is created by app/Database/schema.php at install time;
// this marker records the baseline so future migrations (0002_…) run only on upgrade.
return static function (App\Core\DB $db): void {
};
