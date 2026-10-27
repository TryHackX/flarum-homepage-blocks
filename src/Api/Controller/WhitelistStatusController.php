<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Carbon;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\SentTopics;
use TryHackX\HomepageBlocks\Service\TrackerQueue;
use TryHackX\HomepageBlocks\Service\TrackerSync;

class WhitelistStatusController implements RequestHandlerInterface
{
    public const PROBLEMS = 20;

    public function __construct(
        protected TrackerWhitelistClient $client,
        protected TrackerQueue $queue,
        protected SentTopics $sent,
        protected TrackerSync $sync,
        protected UrlGenerator $url,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        $limits = $this->client->describeLimits();
        $this->sync->schedule(false);
        return new JsonResponse([
            'enabled' => $this->client->isEnabled(),
            'describe' => [
                'enabled' => $this->client->describeEnabled(),
                'paused_until' => $this->client->describePausedUntil(),
                'withdraw' => $limits['withdraw'],
            ],
            'topics' => $this->sent->counts(),
            'queue' => $this->queue->stats(),
            'problems' => $this->problems(),
            'consent' => ['wanted' => $this->sync->consentWanted(), 'confirmed' => $this->sync->consentConfirmed()],
            'cooldown' => $this->client->cooldown(),
            'last_error' => $this->client->lastError(),
        ]);
    }

    private function problems(): array
    {
        $out = [];
        foreach ($this->queue->failing(self::PROBLEMS) as $row) {
            $out[(int) $row->discussion_id] = [
                'discussion_id' => (int) $row->discussion_id,
                'status' => 'failed',
                'detail' => $row->last_error,
                'at' => $row->updated_at !== null ? Carbon::parse($row->updated_at)->getTimestamp() : null,
                'attempts' => (int) $row->attempts,
                'next_at' => Carbon::parse($row->next_at)->getTimestamp(),
            ];
        }
        foreach ($this->sent->problems(self::PROBLEMS) as $row) {
            $out[$row['discussion_id']] ??= [
                'discussion_id' => $row['discussion_id'],
                'status' => $row['status'],
                'detail' => $row['detail'],
                'at' => $row['sent_at'],
                'attempts' => 0,
                'next_at' => null,
            ];
        }
        usort($out, fn ($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
        $out = array_slice($out, 0, self::PROBLEMS);
        $discussions = $out ? Discussion::query()->whereIn('id', array_column($out, 'discussion_id'))->get(['id', 'title', 'slug'])->keyBy('id') : collect();
        return array_map(function (array $problem) use ($discussions) {
            $discussion = $discussions->get($problem['discussion_id']);
            return $problem + ['title' => $discussion?->title, 'url' => $discussion !== null ? $this->link($discussion) : null];
        }, $out);
    }

    private function link(Discussion $discussion): ?string
    {
        try {
            return $this->url->to('forum')->route('discussion', ['id' => $discussion->id . ($discussion->slug ? '-' . $discussion->slug : '')]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
