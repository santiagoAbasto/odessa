<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PressureRating extends Model
{
    use HasFactory;

    protected $fillable = ['label', 'psi'];

    protected function casts(): array
    {
        return ['psi' => 'integer'];
    }
}
