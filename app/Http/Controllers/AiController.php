<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\AiService;

final class AiController extends Controller
{
    public function run(Request $r, string $entity, string $id): Response
    {
        if (!RateLimiter::hit('ai:' . Auth::id(), 20, 60)) {
            throw new HttpException(429, 'Slow down a little — try again in a minute.');
        }
        $text = AiService::run($entity, (int) $id, $r->str('action'), $r->str('extra'));
        return $this->json(['ok' => true, 'text' => $text]);
    }

    public function settings(Request $r): Response
    {
        $cid = Auth::companyId();
        $usage = App::db()->one('SELECT COUNT(*) AS n, COALESCE(SUM(input_tokens), 0) AS tin, COALESCE(SUM(output_tokens), 0) AS tout FROM ai_usage WHERE company_id = ? AND created_at >= ?', [$cid, date('Y-m-01 00:00:00')]);
        $has = [];
        foreach (array_keys(AiService::PROVIDERS) as $p) {
            $has[$p] = (string) setting("ai.$p.key", '') !== '';
        }
        return $this->view('settings/ai', ['title' => 'AI assistant', 'tab' => 'ai', 'has' => $has, 'usage' => $usage, 'today' => AiService::usedToday()]);
    }

    public function save(Request $r): Response
    {
        $p = $r->str('provider');
        $vals = [
            'ai.enabled' => (bool) $r->input('enabled'),
            'ai.provider' => isset(AiService::PROVIDERS[$p]) ? $p : 'anthropic',
            'ai.daily_limit' => max(0, min(100000, $r->int('daily_limit', 200))),
            'ai.include_contact' => (bool) $r->input('include_contact'),
            'ai.custom.allow_private' => (bool) $r->input('custom_allow_private'),
        ];
        foreach (array_keys(AiService::PROVIDERS) as $prov) {
            $vals["ai.$prov.model"] = mb_substr(preg_replace('/[^A-Za-z0-9._:\/\-]/', '', $r->str($prov . '_model')) ?? '', 0, 80);
            $key = trim($r->str($prov . '_key'));
            if ($key !== '') {
                $vals["ai.$prov.key"] = mb_substr($key, 0, 300);
            }
            if ($r->input($prov . '_clear')) {
                $vals["ai.$prov.key"] = '';
            }
        }
        $url = trim($r->str('custom_url'));
        if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
            throw new HttpException(422, 'Enter a valid base URL, for example https://llm.example.com/v1');
        }
        $vals['ai.custom.url'] = mb_substr($url, 0, 255);
        Settings::setMany($vals, Auth::companyId());
        Audit::log('settings.ai', null, null, ['enabled' => $vals['ai.enabled'], 'provider' => $vals['ai.provider']]);
        return $this->back('success', 'AI assistant settings saved.', '/settings/ai');
    }

    public function test(Request $r): Response
    {
        if (!AiService::enabled()) {
            throw new HttpException(422, 'Enable the assistant and save a key first.');
        }
        [$text] = AiService::call('Reply with exactly the word: OK', 'Connection test', 10);
        return $this->json(['ok' => true, 'message' => 'Connected to ' . AiService::PROVIDERS[AiService::provider()] . ' (' . AiService::model() . '): ' . AiService::clip($text, 40)]);
    }
}
