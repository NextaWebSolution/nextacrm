<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            Session::put('_csrf', $t);
        }
        return $t;
    }

    public static function verify(Request $r): void
    {
        if (in_array($r->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $sent = $r->header('X-CSRF-Token') ?? ($r->post['_token'] ?? ($r->json()['_token'] ?? ''));
        $expected = Session::get('_csrf');
        if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419);
        }
    }
}
