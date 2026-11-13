<?php

namespace App\Enums;

/**
 * 1 アカウント 1 ロール（docs/domain-design.md 1 章）。作成時に決まり、変更しない。
 */
enum Role: string
{
    case Seeker = 'seeker';
    case Company = 'company';
}
