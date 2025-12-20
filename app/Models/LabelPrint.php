<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelPrint extends Model
{
    use HasFactory;

    protected $table = 'label_prints';

    protected $fillable = [
        'user_id',
        'batch_id',
        'name',
        'surname',
        'number',
        'designation',
        'company_name',
        'stall_number',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean', // Cast to boolean
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeByBatch($query, string $batchId)
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopePending($query)
    {
        return $query->where('status', false);
    }

    public function scopePrinted($query)
    {
        return $query->where('status', true);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
