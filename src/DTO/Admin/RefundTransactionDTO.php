<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class RefundTransactionDTO
{
    #[Assert\Length(max: 500, maxMessage: 'La raison ne peut pas dépasser {{ limit }} caractères')]
    public ?string $reason = null;
}
