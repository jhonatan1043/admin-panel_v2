<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientLog extends Model
{
    /** Etiqueta del registro que deja el código que envía el cliente (send-test-code). */
    public const TEST_CODE_LABEL = 'Código de prueba';

    protected $fillable = [
        'company_id',
        'project_view_id',
        'operator_id',
        'direction',
        'label',
        'text',
        'status',
        'detail',
        'telegram_message_id',
        'used_at',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
