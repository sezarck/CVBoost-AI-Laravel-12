<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Cv extends Model
{
    protected $fillable=['user_id','title','template','color','content','job_offer','ats_score','ats_analysis','export_paid_at','print_count','modification_count','modification_session_id','modification_session_expires_at'];
    protected $hidden=['modification_session_id','modification_session_expires_at'];
    protected $casts=['content'=>'array','ats_analysis'=>'array','export_paid_at'=>'datetime','modification_session_expires_at'=>'datetime','ats_score'=>'integer','print_count'=>'integer','modification_count'=>'integer'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function payments() { return $this->hasMany(Payment::class); }
}
