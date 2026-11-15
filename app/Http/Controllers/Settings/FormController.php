<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\LookupService;

/** Web-to-lead forms. */
final class FormController extends Controller
{
    public const FIELDS = ['name' => 'Full name', 'company_name' => 'Company', 'email' => 'E-mail', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'job_title' => 'Job title', 'website' => 'Website', 'city' => 'City', 'country' => 'Country', 'industry' => 'Industry', 'company_size' => 'Company size', 'estimated_value' => 'Budget', 'description' => 'Message'];

    public function index(Request $r): Response
    {
        return $this->view('settings/forms', ['title' => 'Web forms', 'tab' => 'forms', 'forms' => App::db()->all('SELECT * FROM lead_forms WHERE company_id = ? ORDER BY id DESC', [Auth::companyId()]), 'users' => LookupService::companyUsers(), 'available' => self::FIELDS]);
    }

    public function save(Request $r): Response
    {
        $fields = [];
        foreach ((array) $r->input('fields', []) as $name) {
            if (isset(self::FIELDS[$name])) {
                $fields[] = ['name' => $name, 'label' => self::FIELDS[$name], 'required' => in_array($name, (array) $r->input('required', []), true)];
            }
        }
        if (!$fields) {
            throw new HttpException(422, 'Select at least one field.');
        }
        $redirect = trim($r->str('redirect_url'));
        if ($redirect !== '' && !preg_match('#^https://#i', $redirect)) {
            throw new HttpException(422, 'Redirect URL must start with https://');
        }
        $assigned = $r->int('assigned_to') ?: null;
        $data = ['name' => mb_substr($r->str('name'), 0, 150) ?: 'Website form', 'fields' => json_encode($fields), 'assigned_to' => $assigned, 'default_status' => mb_substr($r->str('default_status'), 0, 50) ?: 'New', 'default_source' => mb_substr($r->str('default_source'), 0, 50) ?: 'Website', 'success_message' => mb_substr($r->str('success_message'), 0, 500) ?: null, 'redirect_url' => $redirect ?: null, 'is_active' => $r->input('is_active', '1') ? 1 : 0, 'updated_at' => DB::now()];
        $id = $r->int('id');
        if ($id) {
            App::db()->query('UPDATE lead_forms SET name = ?, fields = ?, assigned_to = ?, default_status = ?, default_source = ?, success_message = ?, redirect_url = ?, is_active = ?, updated_at = ? WHERE id = ? AND company_id = ?', array_merge(array_values($data), [$id, Auth::companyId()]));
        } else {
            App::db()->insert('lead_forms', $data + ['company_id' => Auth::companyId(), 'token' => Crypto::token(18), 'created_by' => Auth::id(), 'created_at' => DB::now()]);
        }
        return $this->redirect('/settings/forms', 'success', 'Form saved.');
    }

    public function destroy(Request $r, string $id): Response
    {
        App::db()->query('DELETE FROM lead_forms WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Form deleted', 'reload' => true]);
    }
}
