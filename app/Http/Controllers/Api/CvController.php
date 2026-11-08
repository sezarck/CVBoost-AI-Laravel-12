<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CvController extends Controller
{
    private function rules(): array
    {
        return [
            'title' => 'required|string|max:180',
            'template' => 'required|string|max:80',
            'color' => 'nullable|string|max:30',
            'content' => 'required|array',
            'job_offer' => 'nullable|string',
            'ats_score' => 'nullable|integer|min:0|max:100',
            'ats_analysis' => 'nullable|array',
        ];
    }

    public function index(Request $request)
    {
        return response()->json($request->user()->cvs()->latest()->paginate(20));
    }

    public function store(Request $request)
    {
        $cv = $request->user()->cvs()->create($request->validate($this->rules()));

        return response()->json(['cv' => $cv], 201);
    }

    public function show(Request $request, Cv $cv)
    {
        $this->authorizeOwner($request, $cv);

        return response()->json(['cv' => $cv]);
    }

    public function update(Request $request, Cv $cv)
    {
        $data = $request->validate(array_map(fn ($rule) => 'sometimes|'.$rule, $this->rules()));
        $sessionId = $request->validate([
            'modification_session' => 'nullable|string|size:64',
        ])['modification_session'] ?? null;

        return DB::transaction(function () use ($request, $cv, $data, $sessionId) {
            $cv = Cv::query()->lockForUpdate()->findOrFail($cv->id);
            $this->authorizeOwner($request, $cv);

            $hasContentChanges = false;
            foreach (['title', 'template', 'color', 'content'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $cv->getAttribute($field)) {
                    $hasContentChanges = true;
                    break;
                }
            }

            if ($cv->export_paid_at && $hasContentChanges) {
                $sessionIsValid = $sessionId
                    && $cv->modification_session_id
                    && hash_equals($cv->modification_session_id, $sessionId)
                    && $cv->modification_session_expires_at?->isFuture();

                if (!$sessionIsValid) {
                    return response()->json([
                        'message' => 'La modification incluse dans ce paiement a été utilisée. Effectuez un nouvel export payant pour modifier ce CV.',
                        'code' => 'modification_limit_reached',
                    ], 402);
                }

                if ($cv->modification_count < 1) {
                    $data['modification_count'] = 1;
                }
                $cv->modification_session_expires_at = now()->addHours(2);
                $cv->save();
            }

            $cv->update($data);

            return response()->json(['cv' => $cv->fresh()]);
        });
    }

    public function beginModification(Request $request, Cv $cv)
    {
        return DB::transaction(function () use ($request, $cv) {
            $cv = Cv::query()->lockForUpdate()->findOrFail($cv->id);
            $this->authorizeOwner($request, $cv);

            if (!$cv->export_paid_at) {
                return response()->json([
                    'allowed' => true,
                    'required' => false,
                    'session_id' => null,
                ]);
            }

            if ($cv->modification_session_id && $cv->modification_session_expires_at?->isFuture()) {
                return response()->json([
                    'allowed' => true,
                    'required' => true,
                    'session_id' => $cv->modification_session_id,
                ]);
            }

            if ($cv->modification_count >= 1) {
                return response()->json([
                    'message' => 'La limite de ce paiement est atteinte. Effectuez un nouvel export payant pour modifier ce CV.',
                    'code' => 'modification_limit_reached',
                ], 402);
            }

            $sessionId = Str::random(64);
            $cv->update([
                'modification_session_id' => $sessionId,
                'modification_session_expires_at' => now()->addHours(2),
            ]);

            return response()->json([
                'allowed' => true,
                'required' => true,
                'session_id' => $sessionId,
            ]);
        });
    }

    public function endModification(Request $request, Cv $cv)
    {
        $data = $request->validate(['session_id' => 'required|string|size:64']);
        $this->authorizeOwner($request, $cv);

        if ($cv->modification_session_id && hash_equals($cv->modification_session_id, $data['session_id'])) {
            $cv->update([
                'modification_session_id' => null,
                'modification_session_expires_at' => null,
            ]);
        }

        return response()->json(['message' => 'Session de modification terminée.']);
    }

    public function destroy(Request $request, Cv $cv)
    {
        $this->authorizeOwner($request, $cv);
        $cv->delete();

        return response()->json(['message' => 'CV supprimé.']);
    }

    public function duplicate(Request $request, Cv $cv)
    {
        $this->authorizeOwner($request, $cv);
        $copy = $cv->replicate();
        $copy->title = $cv->title.' (copie)';
        $copy->user_id = $request->user()->id;
        $copy->export_paid_at = null;
        $copy->print_count = 0;
        $copy->modification_count = 0;
        $copy->modification_session_id = null;
        $copy->modification_session_expires_at = null;
        $copy->save();

        return response()->json(['cv' => $copy], 201);
    }

    private function authorizeOwner(Request $request, Cv $cv): void
    {
        abort_unless($cv->user_id === $request->user()->id, 404);
    }
}
