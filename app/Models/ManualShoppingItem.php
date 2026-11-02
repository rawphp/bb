<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\ManualShoppingItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A shopping item the user typed in (SHOP-005). It is on every range's list until deleted. */
#[Fillable(['user_id', 'text'])]
class ManualShoppingItem extends Model
{
    /** @use HasFactory<ManualShoppingItemFactory> */
    use BelongsToUser, HasFactory, HasUlids;
}
