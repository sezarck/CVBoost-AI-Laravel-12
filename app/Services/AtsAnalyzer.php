<?php
namespace App\Services;
class AtsAnalyzer
{
    public function analyze(array $content, string $jobOffer=''): array
    {
        $text=mb_strtolower($this->flatten($content));
        $offer=mb_strtolower($jobOffer);
        preg_match_all('/[\p{L}\p{N}+#.]{3,}/u',$offer,$matches);
        $keywords=array_values(array_unique($matches[0]??[]));
        $matched=array_values(array_filter($keywords,fn($w)=>str_contains($text,$w)));
        $missing=array_values(array_diff($keywords,$matched));
        $sections=['contact'=>!empty($content['contact']??$content['coordinates']??null),'profile'=>!empty($content['profile']??null),'experiences'=>!empty($content['experiences']??null),'education'=>!empty($content['education']??$content['formations']??null),'skills'=>!empty($content['skills']??null)];
        $sectionScore=(count(array_filter($sections))/max(1,count($sections)))*55;
        $keywordScore=count($keywords)?(count($matched)/count($keywords))*35:20;
        $lengthScore=mb_strlen($text)>500?10:(mb_strlen($text)>150?7:3);
        $score=(int)round(min(100,$sectionScore+$keywordScore+$lengthScore));
        $strengths=[]; foreach($sections as $k=>$v)if($v)$strengths[]='La rubrique '.$k.' est renseignée.';
        $suggestions=[];if(!$sections['profile'])$suggestions[]='Ajoutez un profil professionnel clair.';if(!$sections['experiences'])$suggestions[]='Détaillez vos expériences avec des résultats mesurables.';if(!$sections['skills'])$suggestions[]='Ajoutez une rubrique compétences.';if($missing)$suggestions[]='Intégrez naturellement les mots-clés pertinents manquants.';
        return ['score'=>$score,'strengths'=>$strengths,'suggestions'=>$suggestions,'matched_keywords'=>array_slice($matched,0,80),'missing_keywords'=>array_slice($missing,0,80),'sections'=>$sections];
    }
    private function flatten($v): string { if(is_array($v)){ $out='';foreach($v as $x)$out.=' '.$this->flatten($x);return $out;}return is_scalar($v)?(string)$v:''; }
}
