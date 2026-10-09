<?php
declare(strict_types=1);
namespace App;

class ValidationException extends \RuntimeException
{
    public function __construct(public array $errors, public ?int $existingProjectId = null)
    {
        parent::__construct(implode(' ', $errors));
    }
}
