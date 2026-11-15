<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Electronic signatures for quotations and contracts: a drawn (or typed-and-rendered) signature image plus an
 * audit trail — signer name/e-mail, IP, user agent, UTC time and a SHA-256 hash of the document as signed.
 */
final class SignatureService
{
    private const MAX_BYTES = 200_000;

    public static function forRecord(string $entity, int $id): ?array
    {
        return App::db()->one('SELECT * FROM signatures WHERE entity = ? AND record_id = ? ORDER BY id DESC LIMIT 1', [$entity, $id]) ?: null;
    }

    /** Hash of the business content of the document at signing time (detects later edits). */
    public static function documentHash(string $entity, array $rec): string
    {
        $db = App::db();
        $payload = match ($entity) {
            'quotes' => [$rec['number'], $rec['currency'], $rec['total'], $rec['subtotal'], $rec['tax_total'], $db->all("SELECT description, quantity, unit_price, tax_rate, line_total FROM line_items WHERE parent_type = 'quote' AND parent_id = ? ORDER BY sort, id", [(int) $rec['id']])],
            'contracts' => [$rec['title'], $rec['type'], $rec['value'], $rec['currency'], $rec['start_date'], $rec['end_date'], $rec['description']],
            default => $rec,
        };
        return hash('sha256', (string) json_encode($payload));
    }

    public static function validateImage(string $dataUri): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUri, $m)) {
            throw new HttpException(422, 'Please sign in the box before continuing.');
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) > self::MAX_BYTES || !str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
            throw new HttpException(422, 'The signature image is not valid.');
        }
        $size = @getimagesizefromstring($bin);
        if (!$size || $size[0] < 50 || $size[1] < 20 || $size[0] > 2000 || $size[1] > 1000) {
            throw new HttpException(422, 'The signature image is not valid.');
        }
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bin))) {
            // Reject an empty pad: require some visible ink (sampled on a grid).
            $ink = 0;
            $w = imagesx($img);
            $h = imagesy($img);
            for ($x = 0; $x < $w; $x += 2) {
                for ($y = 0; $y < $h; $y += 2) {
                    $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                    if ($c['alpha'] < 100 && ($c['red'] + $c['green'] + $c['blue']) < 600) {
                        $ink++;
                    }
                }
            }
            imagedestroy($img);
            if ($ink < 15) {
                throw new HttpException(422, 'Please sign in the box before continuing.');
            }
        }
        return 'data:image/png;base64,' . base64_encode($bin);
    }

    public static function sign(string $entity, array $rec, string $name, ?string $email, string $image): int
    {
        $name = trim(mb_substr($name, 0, 150));
        if ($name === '') {
            throw new HttpException(422, 'Please type your full name.');
        }
        $req = App::request();
        return App::db()->insert('signatures', [
            'company_id' => (int) $rec['company_id'], 'entity' => $entity, 'record_id' => (int) $rec['id'],
            'signer_name' => $name, 'signer_email' => $email ? mb_substr($email, 0, 190) : null,
            'signer_ip' => $req->ip(), 'user_agent' => mb_substr($req->userAgent(), 0, 255),
            'image' => self::validateImage($image), 'document_hash' => self::documentHash($entity, $rec), 'signed_at' => DB::now(),
        ]);
    }

    /** Public signing link for a contract (token created on first use). */
    public static function contractLink(array $contract): string
    {
        $token = (string) ($contract['public_token'] ?? '');
        if ($token === '') {
            $token = Crypto::token(24);
            App::db()->update('contracts', ['public_token' => $token], ['id' => (int) $contract['id']]);
        }
        return full_url('/p/contract/' . $token);
    }

    public static function stillMatches(array $sig, string $entity, array $rec): bool
    {
        return hash_equals((string) $sig['document_hash'], self::documentHash($entity, $rec));
    }
}
