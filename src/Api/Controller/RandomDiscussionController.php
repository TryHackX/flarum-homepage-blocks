<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Security\PointsManager;
use TryHackX\HomepageBlocks\Security\RecaptchaGuard;

class RandomDiscussionController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Verify reCAPTCHA (scope: random) with optional points-based gating
        $points = new PointsManager($this->settings, $this->paths);
        $guard = new RecaptchaGuard($this->settings, $points);
        $result = $guard->verify($request, 'random');

        if (empty($result['ok'])) {
            if (!empty($result['captcha_required'])) {
                return new JsonResponse([
                    'error' => 'captcha_required',
                    'captcha_required' => true,
                    'balance' => $result['balance'] ?? 0,
                    'cost' => $result['cost'] ?? null,
                ], 403);
            }
            return new JsonResponse(['error' => 'reCAPTCHA verification failed'], 403);
        }

        $tagSlug = $request->getQueryParams()['tag'] ?? null;

        if (!$tagSlug) {
            return new JsonResponse(['error' => 'Tag slug is required'], 400);
        }

        $tag = Tag::where('slug', $tagSlug)->first();

        if (!$tag) {
            return new JsonResponse(['error' => 'Tag not found'], 404);
        }

        // Get all tag IDs (parent + children) for broader matching
        $tagIds = [$tag->id];
        $children = Tag::where('parent_id', $tag->id)->pluck('id')->toArray();
        $tagIds = array_merge($tagIds, $children);

        $discussion = Discussion::whereHas('tags', function ($query) use ($tagIds) {
            $query->whereIn('tags.id', $tagIds);
        })
            ->where('is_private', false)
            ->whereNull('hidden_at')
            ->inRandomOrder()
            ->first();

        if (!$discussion) {
            return new JsonResponse(['error' => 'No discussions found for this tag'], 404);
        }

        return new JsonResponse([
            'data' => [
                'id' => $discussion->id,
                'slug' => $discussion->slug,
                'title' => $discussion->title,
            ],
            'balance' => $result['balance'] ?? null,
            'cost' => $result['cost'] ?? null,
            'refilled' => $result['refilled'] ?? false,
        ]);
    }
}
