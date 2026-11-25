<?php

declare(strict_types=1);

namespace App\Core\Listing\Enums;

enum ListingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
