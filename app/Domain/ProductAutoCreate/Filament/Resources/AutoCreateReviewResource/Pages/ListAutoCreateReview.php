<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListAutoCreateReview extends ListRecords
{
    protected static string $resource = AutoCreateReviewResource::class;
}
