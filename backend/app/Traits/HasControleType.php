<?php

namespace App\Traits;

trait HasControleType
{
    private function getControleType(int $numero, int $total): string
    {
        if ($total <= 2) {
            return 'cc';
        }
        if ($numero === $total - 1) {
            return 'theorique';
        }
        if ($numero === $total) {
            return 'pratique';
        }
        return 'cc';
    }
}
