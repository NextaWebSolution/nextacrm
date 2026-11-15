<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\CompanyService;
use App\Services\FileService;
use App\Services\LookupService;
use App\Services\Seeder;

final class CompanyController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('settings/company', ['title' => 'Company', 'tab' => 'company', 'company' => App::db()->find('companies', Auth::companyId()), 'companies' => App::db()->all('SELECT c.* FROM companies c JOIN company_user cu ON cu.company_id = c.id AND cu.user_id = ? ORDER BY c.name', [Auth::id()]), 'currencies' => LookupService::currencies()]);
    }

    public function save(Request $r): Response
    {
        $s = fn ($k, $n = 190) => mb_substr(trim($r->str($k)), 0, $n) ?: null;
        $data = [
            'name' => $s('name') ?? 'Company', 'legal_name' => $s('legal_name'), 'email' => filter_var($r->str('email'), FILTER_VALIDATE_EMAIL) ?: null,
            'phone' => $s('phone', 50), 'website' => $s('website'), 'address' => mb_substr($r->str('address'), 0, 1000) ?: null,
            'city' => $s('city', 100), 'state' => $s('state', 100), 'country' => $s('country', 100), 'postal_code' => $s('postal_code', 20),
            'tax_id' => $s('tax_id', 50), 'timezone' => in_array($r->str('timezone'), \DateTimeZone::listIdentifiers(), true) ? $r->str('timezone') : 'UTC',
            'brand_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $r->str('brand_color')) ? $r->str('brand_color') : '#4f46e5',
            'invoice_prefix' => $s('invoice_prefix', 20) ?? 'INV-', 'quote_prefix' => $s('quote_prefix', 20) ?? 'QT-', 'ticket_prefix' => $s('ticket_prefix', 20) ?? 'TKT-',
            'invoice_next' => max(1, $r->int('invoice_next', 1)), 'quote_next' => max(1, $r->int('quote_next', 1)),
            'payment_terms_days' => max(0, min(365, $r->int('payment_terms_days', 15))),
            'invoice_terms' => mb_substr($r->str('invoice_terms'), 0, 5000) ?: null, 'invoice_footer' => mb_substr($r->str('invoice_footer'), 0, 1000) ?: null,
            'bank_details' => mb_substr($r->str('bank_details'), 0, 2000) ?: null, 'updated_at' => DB::now(),
        ];
        $cur = strtoupper($r->str('currency'));
        if (App::db()->value('SELECT id FROM currencies WHERE code = ? AND is_active = 1', [$cur])) {
            $data['currency'] = $cur;
        }
        $logo = $r->file('logo');
        if ($logo && $logo['error'] === UPLOAD_ERR_OK) {
            $f = FileService::upload($logo, 'companies', Auth::companyId(), null, true);
            $data['logo_file_id'] = $f['id'];
        }
        if ($r->input('remove_logo')) {
            $data['logo_file_id'] = null;
        }
        App::db()->update('companies', $data, ['id' => Auth::companyId()]);
        CompanyService::flush();
        Audit::log('settings.company', 'companies', Auth::companyId());
        return $this->back('success', 'Company profile saved.');
    }

    public function create(Request $r): Response
    {
        $name = mb_substr(trim($r->str('name')), 0, 190);
        if ($name === '') {
            throw new HttpException(422, 'Company name is required.');
        }
        $cur = strtoupper($r->str('currency')) ?: 'INR';
        $db = App::db();
        $id = $db->transaction(function () use ($db, $name, $cur) {
            $id = $db->insert('companies', ['name' => $name, 'legal_name' => $name, 'currency' => $cur, 'timezone' => CompanyService::current()['timezone'] ?? 'UTC', 'created_at' => DB::now(), 'updated_at' => DB::now()]);
            $db->insert('company_user', ['company_id' => $id, 'user_id' => Auth::id()]);
            Seeder::seedCompanyDefaults($db, $id, $cur, '');
            return $id;
        });
        Audit::log('company.created', 'companies', $id);
        Session::put('company_id', $id);
        return $this->redirect('/settings/company', 'success', "Company \"$name\" created. You are now working in it.");
    }
}
