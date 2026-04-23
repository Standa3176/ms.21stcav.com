<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource;
use Filament\Resources\Pages\EditRecord;

class EditAutoCreateReview extends EditRecord
{
    protected static string $resource = AutoCreateReviewResource::class;
}
