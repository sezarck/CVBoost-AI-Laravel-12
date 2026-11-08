<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Cv;
use App\Services\AtsAnalyzer;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
class CvAnalysisController extends Controller
{
    public function analyze(Request $r,AtsAnalyzer $ats){
        $data=$r->validate(['cv_id'=>'nullable|integer','content'=>'required_without:cv_id|array','job_offer'=>'nullable|string|max:30000']);
        $content=$data['content']??$r->user()->cvs()->findOrFail($data['cv_id'])->content;
        $analysis=$ats->analyze($content,$data['job_offer']??'');
        if(!empty($data['cv_id'])){ $cv=$r->user()->cvs()->findOrFail($data['cv_id']);$cv->update(['ats_score'=>$analysis['score'],'ats_analysis'=>$analysis,'job_offer'=>$data['job_offer']??$cv->job_offer]);}
        return response()->json($analysis);
    }
    public function optimize(Request $r,AiService $ai){
        $data=$r->validate(['content'=>'required|array','job_offer'=>'required|string|max:30000','section'=>'nullable|string|max:80']);
        try{return response()->json(['proposal'=>$ai->improve($data['content'],$data['job_offer'],$data['section']??null)]);}
        catch(\Throwable $e){Log::error('CV AI failure',['error'=>$e->getMessage()]);return response()->json(['message'=>'Le service IA est temporairement indisponible.'],503);}
    }
}
