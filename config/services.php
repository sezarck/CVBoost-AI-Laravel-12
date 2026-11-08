<?php
return [
 'gemini'=>['key'=>env('GEMINI_API_KEY'),'model'=>env('GEMINI_MODEL','gemini-flash-lite-latest')],
 'groq'=>['key'=>env('GROQ_API_KEY'),'model'=>env('GROQ_MODEL','openai/gpt-oss-120b')],
 'saspay'=>['base_url'=>env('SASPAY_BASE_URL','https://api.saspay.me/api/v1'),'secret_key'=>env('SASPAY_SECRET_KEY'),'country'=>env('SASPAY_COUNTRY','CM'),'return_frontend_url'=>env('FRONTEND_URL','http://localhost:3000')],
 'cv'=>['export_price'=>(int)env('CV_EXPORT_PRICE',500),'currency'=>env('CV_EXPORT_CURRENCY','XAF'),'import_max_kb'=>(int)env('CV_IMPORT_MAX_KB',10240)],
];
