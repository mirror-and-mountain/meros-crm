<?php 

namespace MM\Meros\Crm\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contact extends Model {
    protected $table = 'meros_crm_contacts';
    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'data',
    ];

    protected array $casts = [
        'data' => 'array',
    ];

    public function user(): HasOne {
        return $this->hasOne(User::class, 'ID', 'user_id');
    }
}