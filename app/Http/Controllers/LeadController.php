<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\Request;
use App\Core\Response;
use App\Services\LeadService;

final class LeadController extends Controller
{
    public function convert(Request $r, string $id): Response
    {
        $this->service('leads');
        $res = LeadService::convert((int) $id, [
            'customer_id' => $r->int('customer_id') ?: null,
            'create_deal' => (bool) $r->input('create_deal', false),
            'deal_name' => $r->str('deal_name'),
            'deal_amount' => $r->str('deal_amount') !== '' ? (float) $r->str('deal_amount') : null,
            'pipeline_id' => $r->int('pipeline_id') ?: null,
            'expected_close' => $r->str('expected_close') ?: null,
        ]);
        $to = $res['deal_id'] ? '/r/deals/' . $res['deal_id'] : '/r/customers/' . $res['customer_id'];
        return $this->json(['ok' => true, 'message' => 'Lead converted', 'redirect' => url($to)]);
    }
}
