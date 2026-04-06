<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DayClose extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'close_date',
        'closed_at',
        'total_sales',
        'total_revenue',
        'summary'
    ];

    protected $casts = [
        'close_date' => 'date',
        'closed_at' => 'datetime',
        'total_revenue' => 'decimal:2',
        'summary' => 'array'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}