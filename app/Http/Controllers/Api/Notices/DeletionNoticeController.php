<?php

namespace App\Http\Controllers\Api\Notices;

use App\Http\Controllers\Controller;
use App\Models\PatientDeletionNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * BR-18: a nutritionist's notices that patients deleted their own accounts.
 * Each is only `{ id, patient_code, deleted_at }` — nothing else about the
 * patient survives the deletion. Own notices only (the caller's id is the
 * filter); another nutritionist's notice is a 404.
 */
class DeletionNoticeController extends Controller
{
    public function index(): JsonResponse
    {
        $notices = PatientDeletionNotice::query()
            ->where('nutritionist_id', Auth::id())
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PatientDeletionNotice $notice) => [
                'id' => $notice->id,
                'patient_code' => $notice->patient_code,
                'deleted_at' => $notice->deleted_at->toIso8601String(),
            ]);

        return response()->json($notices);
    }

    /** Dismiss: the notice has been seen, so it is deleted for good. */
    public function destroy(string $id): Response
    {
        PatientDeletionNotice::query()->where('nutritionist_id', Auth::id())->findOrFail($id)->delete();

        return response()->noContent();
    }
}
