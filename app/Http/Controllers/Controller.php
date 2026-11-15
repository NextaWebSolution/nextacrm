<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\EntityRegistry;
use App\Services\RecordService;

abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'app'): Response
    {
        return Response::view($template, $data, 200, $layout);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function back(string $type = '', string $message = '', string $fallback = '/'): Response
    {
        if ($message !== '') {
            Session::flash($type ?: 'success', $message);
        }
        return Response::back($fallback);
    }

    protected function redirect(string $path, string $type = '', string $message = ''): Response
    {
        if ($message !== '') {
            Session::flash($type ?: 'success', $message);
        }
        return Response::redirect($path);
    }

    /** Respond to AJAX with JSON, to classic forms with a redirect + flash. */
    protected function done(Request $r, string $message, string $redirect, array $extra = []): Response
    {
        if ($r->wantsJson()) {
            return $this->json(['ok' => true, 'message' => $message, 'redirect' => url($redirect)] + $extra);
        }
        return $this->redirect($redirect, 'success', $message);
    }

    protected function service(string $entity): RecordService
    {
        if (!EntityRegistry::exists($entity)) {
            throw new HttpException(404);
        }
        $svc = RecordService::for($entity);
        if (!module_enabled($svc->module)) {
            throw new HttpException(404, 'This module is disabled.');
        }
        return $svc;
    }

    protected function withOld(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        Session::put('_old', $input);
    }
}
