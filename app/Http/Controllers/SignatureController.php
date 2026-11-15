<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RecordService;
use App\Services\SignatureService;

/** Staff-side e-signature actions: contract signing links and signed certificates. */
final class SignatureController extends Controller
{
    public function contractLink(Request $r, string $id): Response
    {
        $c = RecordService::for('contracts')->find((int) $id, 'edit');
        if ($c['status'] === 'Draft') {
            throw new HttpException(422, 'Set the contract status to Active (or another non-draft status) before sending it for signature.');
        }
        $link = SignatureService::contractLink(App::db()->find('contracts', (int) $id));
        return $this->json(['ok' => true, 'message' => 'Signing link ready — copied to your clipboard.', 'link' => $link]);
    }

    /** PDF certificate: document summary + signature + audit trail. */
    public function certificate(Request $r, string $entity, string $id): Response
    {
        if (!in_array($entity, ['quotes', 'contracts'], true)) {
            throw new HttpException(404);
        }
        $rec = RecordService::for($entity)->find((int) $id);
        $sig = SignatureService::forRecord($entity, (int) $id);
        if (!$sig) {
            throw new HttpException(404, 'This record has not been signed yet.');
        }
        $raw = App::db()->find($entity, (int) $id);
        $html = \App\Core\View::partial('documents/signature_certificate', [
            'entity' => $entity, 'rec' => $rec, 'sig' => $sig, 'company' => \App\Services\CompanyService::current(),
            'intact' => SignatureService::stillMatches($sig, $entity, $raw),
        ]);
        $pdf = \App\Services\SalesService::renderPdf($html);
        return Response::download($pdf, 'signature-certificate-' . $entity . '-' . (int) $id . '.pdf', str_starts_with($pdf, '%PDF') ? 'application/pdf' : 'text/html');
    }
}
