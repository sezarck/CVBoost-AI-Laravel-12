<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class AiService
{
    public function improve(array $cv,string $jobOffer,?string $section=null): array
    {
        $prompt="Tu es un expert en recrutement et CV ATS. Réponds uniquement en JSON valide avec les clés professional_profile (string), experience_titles (array de strings), experience_bullets (array de strings), analysis (string). experience_titles doit contenir exactement un intitulé amélioré pour chaque expérience du CV, dans le même ordre. experience_bullets doit contenir exactement une reformulation pour chaque puce existante, dans l'ordre des expériences puis des puces. Ne crée aucune nouvelle expérience, puce, compétence ou information et n'invente aucun fait. CV: ".json_encode($cv,JSON_UNESCAPED_UNICODE)." Offre: ".$jobOffer." Section ciblée: ".($section??'toutes');
        $gemini=config('services.gemini.key');
        if($gemini){
            $url='https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':generateContent';
            $res=Http::timeout(45)->post($url.'?key='.$gemini,['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']]);
            if($res->successful()){ $raw=$res->json('candidates.0.content.parts.0.text');$parsed=json_decode($raw,true);if(is_array($parsed)&&$this->validProposal($parsed,$cv))return $parsed; }
        }
        $groq=config('services.groq.key');
        if($groq){
            $res=Http::withToken($groq)->timeout(45)->post('https://api.groq.com/openai/v1/chat/completions',['model'=>config('services.groq.model'),'response_format'=>['type'=>'json_object'],'messages'=>[['role'=>'user','content'=>$prompt]]]);
            if($res->successful()){ $raw=$res->json('choices.0.message.content');$parsed=json_decode($raw,true);if(is_array($parsed)&&$this->validProposal($parsed,$cv))return $parsed; }
        }
        throw new RuntimeException('Service IA indisponible. Configurez GEMINI_API_KEY ou GROQ_API_KEY.');
    }

    private function validProposal(array $proposal,array $cv): bool
    {
        if(!is_string($proposal['professional_profile']??null)||!is_string($proposal['analysis']??null))return false;
        $experiences=$cv['experiences']??[];
        if(!is_array($experiences)||!is_array($proposal['experience_titles']??null)||count($proposal['experience_titles'])!==count($experiences))return false;
        if(!array_reduce($proposal['experience_titles'],fn($valid,$title)=>$valid&&is_string($title),true))return false;
        $bulletCount=0;
        foreach($experiences as $experience){
            if(!is_array($experience)||!is_array($experience['bullets']??null))return false;
            $bulletCount+=count($experience['bullets']);
        }
        return is_array($proposal['experience_bullets']??null)
            &&count($proposal['experience_bullets'])===$bulletCount
            &&array_reduce($proposal['experience_bullets'],fn($valid,$bullet)=>$valid&&is_string($bullet),true);
    }
}
