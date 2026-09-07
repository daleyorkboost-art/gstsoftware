<?php
declare(strict_types=1);
namespace App;
interface GSTProvider
{
    public function submit(string $kind, array $invoice): array;
}
