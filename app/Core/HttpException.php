<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

class HttpException extends \RuntimeException
{
    public function __construct(public int $status, string $message = '', public array $errors = [])
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Please sign in to continue',
            403 => 'You do not have permission to do that',
            404 => 'The page or record could not be found',
            405 => 'Method not allowed',
            419 => 'Your session expired. Refresh the page and try again',
            422 => 'Please correct the highlighted fields',
            429 => 'Too many requests. Please wait and try again',
            default => 'Something went wrong',
        };
    }
}
