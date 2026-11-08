<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
class CvImportController extends Controller
{
    public function store(Request $r){
        $max=(int)config('services.cv.import_max_kb',10240);
        $r->validate(['file'=>'required|file|max:'.$max.'|mimes:pdf,docx,txt,md']);
        $file=$r->file('file');$ext=strtolower($file->getClientOriginalExtension());$text='';
        try{
            if($ext==='pdf'){$pdf=(new PdfParser())->parseFile($file->getRealPath());$text=$pdf->getText();}
            elseif($ext==='docx'){$doc=IOFactory::load($file->getRealPath());foreach($doc->getSections() as $section)foreach($section->getElements() as $el){if(method_exists($el,'getText'))$text.=$el->getText()."\n";}}
            else $text=file_get_contents($file->getRealPath());
        }catch(\Throwable $e){return response()->json(['message'=>'Impossible de lire ce fichier. Vérifiez son format.'],422);}
        $text=trim(preg_replace('/[ \t]+/u',' ',$text));
        if($text==='')return response()->json(['message'=>'Aucun texte exploitable. Les PDF scannés nécessitent un OCR non inclus.'],422);
        return response()->json(['filename'=>$file->getClientOriginalName(),'text'=>mb_substr($text,0,100000),'characters'=>mb_strlen($text)]);
    }
}
