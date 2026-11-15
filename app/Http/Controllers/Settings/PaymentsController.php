<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Http\Controllers\Controller;

/** Gateway credentials are stored per company and encrypted at rest; secrets are never sent back to the browser. */
final class PaymentsController extends Controller
{
    private const SECRETS = ['razorpay' => ['key_secret', 'webhook_secret'], 'stripe' => ['secret_key', 'webhook_secret'], 'paypal' => ['client_secret']];
    private const PLAIN = ['razorpay' => ['key_id'], 'stripe' => [], 'paypal' => ['client_id', 'webhook_id']];

    public function index(Request $r): Response
    {
        $has = [];
        foreach (self::SECRETS as $g => $keys) {
            foreach ($keys as $k) {
                $has[$g][$k] = (string) setting("pay.$g.$k", '') !== '';
            }
        }
        return $this->view('settings/payments', ['title' => 'Online payments', 'tab' => 'payments', 'has' => $has, 'cid' => Auth::companyId()]);
    }

    public function save(Request $r): Response
    {
        $values = [];
        foreach (array_keys(self::SECRETS) as $g) {
            $values["pay.$g.enabled"] = (bool) $r->input("{$g}_enabled");
            foreach (self::PLAIN[$g] as $k) {
                $values["pay.$g.$k"] = mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $r->str("{$g}_$k")) ?? '', 0, 120);
            }
            foreach (self::SECRETS[$g] as $k) {
                $v = trim($r->str("{$g}_$k"));
                if ($v !== '') {
                    $values["pay.$g.$k"] = mb_substr($v, 0, 255);
                }
            }
        }
        $values['pay.paypal.sandbox'] = (bool) $r->input('paypal_sandbox');
        Settings::setMany($values, Auth::companyId());
        Audit::log('settings.payments', null, null, ['enabled' => array_keys(array_filter([
            'razorpay' => $values['pay.razorpay.enabled'], 'stripe' => $values['pay.stripe.enabled'], 'paypal' => $values['pay.paypal.enabled'],
        ]))]);
        return $this->back('success', 'Payment settings saved.', '/settings/payments');
    }
}
