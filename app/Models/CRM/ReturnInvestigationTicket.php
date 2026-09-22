<?php

namespace App\Models\CRM;

use App\Models\Core\User;
use App\Models\Hr\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class ReturnInvestigationTicket extends Model
{
    protected $table = 'crm_return_investigation_tickets';

    protected $fillable = [
        'return_id',
        'reference_number',
        'store_id',
        'created_by',
        'expected_investigation_date',
        'notes',
        'findings',
        'findings_attachment_path',
        'recommended_resolution',
        'completed_by',
        'completed_at',
        'status',
    ];

    protected $casts = [
        'expected_investigation_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
    ];

    protected $appends = ['findings_attachment_url'];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(EcommerceOrderReturn::class, 'return_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function getFindingsAttachmentUrlAttribute(): ?string
    {
        if (!$this->findings_attachment_path) {
            return null;
        }

        return Storage::disk('public')->url($this->findings_attachment_path);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'crm_return_investigation_assignees',
            'ticket_id',
            'employee_id'
        )->withPivot('user_id')->withTimestamps();
    }
}
