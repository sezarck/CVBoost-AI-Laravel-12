<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'cv_id',
        'order_id',
        'provider',
        'provider_reference',
        'amount',
        'currency',
        'operator',
        'phone_number',
        'status',
        'payment_link',
        'provider_payload',
        'verified_at',
    ];

    protected $casts = [
        'provider_payload' => 'array',
        'verified_at' => 'datetime',
        'amount' => 'decimal:2', // CORRECTION : Passage en décimal (2 chiffres après la virgule)
    ];

    public function user(): BelongsTo 
    { 
        return $this->belongsTo(User::class); 
    }

    public function cv(): BelongsTo 
    { 
        return $this->belongsTo(Cv::class); 
    }
}