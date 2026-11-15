<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\Audit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Http\Controllers\Controller;

final class GeneralController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('settings/general', ['title' => 'General settings', 'tab' => 'general']);
    }

    public function save(Request $r): Response
    {
        $formats = ['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'M j, Y'];
        Settings::setMany([
            'app.name' => mb_substr(trim($r->str('app_name')), 0, 60) ?: 'NextaCRM',
            'app.date_format' => in_array($r->str('date_format'), $formats, true) ? $r->str('date_format') : 'd M Y',
            'app.time_format' => in_array($r->str('time_format'), ['H:i', 'h:i A'], true) ? $r->str('time_format') : 'H:i',
            'app.week_start' => $r->int('week_start') === 0 ? 0 : 1,
            'app.per_page' => max(10, min(100, $r->int('per_page', 25))),
        ]);
        Audit::log('settings.general');
        return $this->back('success', 'Settings saved.');
    }
}
