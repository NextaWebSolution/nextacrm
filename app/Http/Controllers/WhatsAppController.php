<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\EntityRegistry;
use App\Services\RecordService;
use App\Services\WhatsAppService;

final class WhatsAppController extends Controller
{
    /** POST /r/{entity}/{id}/whatsapp — send from a record (needs edit access to it). */
    public function send(Request $r, string $entity, string $id): Response
    {
        if (!EntityRegistry::exists($entity)) {
            throw new HttpException(404);
        }
        $svc = RecordService::for($entity);
        $rec = $svc->find((int) $id);
        if (!Permissions::can($svc->module, 'edit', $rec)) {
            throw new HttpException(403);
        }
        $to = $r->str('to') !== '' ? $r->str('to') : WhatsAppService::phoneFor($rec);
        $template = preg_match('/^[a-z0-9_]{1,100}$/', $r->str('template')) ? $r->str('template') : null;
        WhatsAppService::send($to, $r->str('message'), $entity, (int) $id, $template);
        return $this->json(['ok' => true, 'message' => 'WhatsApp message sent.', 'reload' => true]);
    }

    public function settings(Request $r): Response
    {
        $has = fn ($k) => (string) setting($k, '') !== '';
        $log = \App\Core\App::db()->all('SELECT to_number, body, status, error, provider, created_at FROM whatsapp_messages WHERE company_id = ? ORDER BY id DESC LIMIT 15', [Auth::companyId()]);
        return $this->view('settings/whatsapp', ['title' => 'WhatsApp', 'tab' => 'whatsapp', 'log' => $log, 'has' => ['meta' => $has('wa.meta.token'), 'twilio' => $has('wa.twilio.token'), 'gupshup' => $has('wa.gupshup.api_key')]]);
    }

    public function save(Request $r): Response
    {
        $p = $r->str('provider');
        $v = [
            'wa.provider' => isset(WhatsAppService::PROVIDERS[$p]) ? $p : '',
            'wa.default_cc' => preg_replace('/\D/', '', $r->str('default_cc')) ?: '91',
            'wa.meta.phone_id' => preg_replace('/\D/', '', $r->str('meta_phone_id')) ?? '',
            'wa.meta.language' => preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $r->str('meta_language')) ? $r->str('meta_language') : 'en',
            'wa.twilio.sid' => preg_replace('/[^A-Za-z0-9]/', '', $r->str('twilio_sid')) ?? '',
            'wa.twilio.from' => preg_replace('/[^\d+]/', '', $r->str('twilio_from')) ?? '',
            'wa.gupshup.source' => preg_replace('/\D/', '', $r->str('gupshup_source')) ?? '',
            'wa.gupshup.app' => mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $r->str('gupshup_app')) ?? '', 0, 60),
        ];
        foreach (['meta_token' => 'wa.meta.token', 'twilio_token' => 'wa.twilio.token', 'gupshup_api_key' => 'wa.gupshup.api_key'] as $in => $key) {
            if (trim($r->str($in)) !== '') {
                $v[$key] = mb_substr(trim($r->str($in)), 0, 1000);
            }
        }
        Settings::setMany($v, Auth::companyId());
        Audit::log('settings.whatsapp', null, null, ['provider' => $v['wa.provider']]);
        return $this->back('success', 'WhatsApp settings saved.', '/settings/whatsapp');
    }

    public function test(Request $r): Response
    {
        WhatsAppService::send($r->str('to'), 'Test message from ' . setting('app.name', 'NextaCRM') . ' — WhatsApp is connected.');
        return $this->json(['ok' => true, 'message' => 'Test message sent.', 'reload' => true]);
    }
}
