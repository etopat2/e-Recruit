<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'from_status', 'to_status', 'reason', 'changed_by', 'source'])]
class ApplicationStatusHistory extends Model
{
    use HasUlids;

    protected $table = 'application_status_history';

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
