<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class RedirectException extends HttpException
{
    public function __construct(public string $to)
    {
        parent::__construct(302, 'Redirect');
    }
}
