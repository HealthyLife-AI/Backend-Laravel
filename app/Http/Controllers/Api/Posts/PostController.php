<?php

namespace App\Http\Controllers\Api\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\SavePostRequest;
use App\Http\Resources\PostResource;
use App\Models\NutritionistPost;
use App\Services\Posts\PostService;
use App\Support\ConditionCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 3, A3: the nutritionist's own education posts. Every id-based action
 * is for the AUTHOR only: another nutritionist's post is a 404, like a patient
 * of another nutritionist.
 */
class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    public function index(): AnonymousResourceCollection
    {
        return PostResource::collection(
            NutritionistPost::query()->where('author_id', Auth::id())->withCount('reads')
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(20)
        );
    }

    public function store(SavePostRequest $request): JsonResponse
    {
        $post = $this->posts->create($request->user(), $request->validated());

        return (new PostResource($post))->response()->setStatusCode(201);
    }

    public function show(NutritionistPost $post): PostResource
    {
        return new PostResource($this->owned($post)->loadCount('reads'));
    }

    public function update(SavePostRequest $request, NutritionistPost $post): PostResource
    {
        return new PostResource($this->posts->update($this->owned($post), $request->validated())->loadCount('reads'));
    }

    public function destroy(NutritionistPost $post): Response
    {
        $this->owned($post)->delete();

        return response()->noContent();
    }

    public function publish(NutritionistPost $post): PostResource
    {
        return new PostResource($this->posts->publish($this->owned($post))->loadCount('reads'));
    }

    public function unpublish(NutritionistPost $post): PostResource
    {
        return new PostResource($this->posts->unpublish($this->owned($post))->loadCount('reads'));
    }

    /** `{ audience_count, read_count }`. */
    public function stats(NutritionistPost $post): JsonResponse
    {
        return response()->json($this->posts->stats($this->owned($post)));
    }

    /**
     * Ready-made tip lines for the editor ("من النصائح الجاهزة"), from
     * config/patient_tips.php. A tip shared by several conditions is listed once
     * with all its codes; `condition_codes` is empty for the general ones.
     */
    public function templates(): JsonResponse
    {
        $byText = [];

        foreach ((array) config('patient_tips') as $key => $lines) {
            foreach ($lines as $line) {
                $byText[$line] ??= [];
                if ($key !== 'general' && isset(ConditionCodes::LABELS_AR[$key])) {
                    $byText[$line][] = $key;
                }
            }
        }

        $templates = [];
        foreach ($byText as $text => $codes) {
            $templates[] = ['text' => (string) $text, 'condition_codes' => $codes];
        }

        return response()->json($templates);
    }

    private function owned(NutritionistPost $post): NutritionistPost
    {
        abort_unless($post->author_id === Auth::id(), 404);

        return $post;
    }
}
