<?php

namespace TryHackX\HomepageBlocks\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

final class TrackerSync
{
    public const BUDGET_SEC = 20.0;
    public const CONSENT_SENT = TrackerWhitelistClient::S . '.whitelist_image_copy_sent';
    private const LOCK = 'whitelist:drain';
    private const CONSENT_TRY_KEY = 'whitelist:consent_try';
    private const CONSENT_RETRY_SEC = 300;
    private const ROWS_PER_PASS = 20;

    private static bool $deferred = false;
    private static bool $logged = false;

    public function __construct(
        private TrackerWhitelistClient $client,
        private WhitelistScanner $scanner,
        private TrackerQueue $queue,
        private SettingsRepositoryInterface $settings,
        private Store $store,
        private LoggerInterface $logger,
        private ConnectionInterface $db,
    ) {
    }

    public function schedule(bool $inline = true): void
    {
        if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
            if (self::$deferred) return;
            self::$deferred = true;
            register_shutdown_function(function (): void {
                fastcgi_finish_request();
                $this->drain();
            });
            return;
        }
        if ($inline) $this->afterCommit(fn () => $this->drain());
    }

    private function afterCommit(callable $fn): void
    {
        if (method_exists($this->db, 'afterCommit')) {
            try {
                $this->db->afterCommit($fn);
                return;
            } catch (\RuntimeException $e) {
            }
        }
        $fn();
    }

    public function drain(float $budget = self::BUDGET_SEC): array
    {
        $idle = ['ran' => false, 'synced' => 0, 'retried' => 0];
        try {
            if (!$this->client->isEnabled() || !$this->client->isConfigured()) return $idle;
            $r = $this->store->withLock(self::LOCK, fn () => $this->run($budget), false, null);
            return is_array($r) ? $r : $idle;
        } catch (\Throwable $e) {
            $this->logOnce('whitelist queue error: ' . $e->getMessage());
            return $idle;
        }
    }

    private function run(float $budget): array
    {
        $t0 = microtime(true);
        $out = ['ran' => true, 'synced' => 0, 'retried' => 0];
        if ($this->client->inCooldown()) return $out;
        $this->deliverConsent();
        $pinged = false;
        while (!$this->client->inCooldown() && microtime(true) - $t0 < $budget) {
            $rows = $this->queue->due(self::ROWS_PER_PASS);
            if ($rows->isEmpty()) break;
            if (!$pinged && $this->client->describeEnabled() && $this->client->limitsStale()) {
                $pinged = true;
                $this->client->ping(null, 6, null);
                if ($this->client->inCooldown()) break;
            }
            foreach ($rows as $row) {
                if (microtime(true) - $t0 >= $budget || $this->client->inCooldown()) break 2;
                $result = $this->one($row);
                if ($result === 'stop') break 2;
                $out[$result === 'ok' ? 'synced' : 'retried']++;
            }
        }
        return $out;
    }

    private function one(object $row): string
    {
        try {
            $r = $this->scanner->syncDiscussion((int) $row->discussion_id, (string) $row->reason, $row->post_id !== null ? (int) $row->post_id : null);
        } catch (\Throwable $e) {
            $this->queue->retry($row, $e->getMessage(), true, false);
            $this->logOnce('whitelist sync error for discussion ' . $row->discussion_id . ': ' . $e->getMessage());
            return 'retry';
        }
        if ($r['ok']) {
            $this->queue->done($row);
            return 'ok';
        }
        $magnetsDone = !empty($r['magnets_done']);
        if (!empty($r['paused'])) {
            $this->queue->retry($row, null, false, $magnetsDone, null, (int) ($r['until'] ?? time() + 600));
            return 'retry';
        }
        if (!empty($r['throttled'])) {
            $this->queue->retry($row, null, false, $magnetsDone, (int) ($r['retry_after'] ?? 60));
            return 'stop';
        }
        $this->queue->retry($row, (string) ($r['error'] ?? 'tracker_error'), true, $magnetsDone);
        $status = (int) ($r['status'] ?? 0);
        $this->logOnce('whitelist sync failed for discussion ' . $row->discussion_id . ': ' . ($r['error'] ?? 'unknown')
            . ($status === 403 || $status === 401 ? ' — check the API key/secret; the tracker may have banned this server IP' : ''));
        return 'retry';
    }

    public function consentWanted(): bool
    {
        return $this->client->isEnabled() && TrackerWhitelistClient::truthy($this->settings->get(TrackerWhitelistClient::S . '.whitelist_image_copy'));
    }

    public function consentConfirmed(): ?bool
    {
        $v = $this->settings->get(self::CONSENT_SENT);
        return $v === null || $v === '' ? null : TrackerWhitelistClient::truthy($v);
    }

    public function forgetConsent(): void
    {
        if ($this->consentConfirmed() !== null) $this->settings->set(self::CONSENT_SENT, '');
    }

    private function deliverConsent(): void
    {
        $wanted = $this->consentWanted();
        if ($this->consentConfirmed() === $wanted) return;
        $last = $this->store->read(self::CONSENT_TRY_KEY);
        if ($last && ($last['value']['wanted'] ?? null) === $wanted && time() - (int) ($last['value']['at'] ?? 0) < self::CONSENT_RETRY_SEC) return;
        $r = $this->client->ping(null, 6, $wanted);
        if (!$r['ok'] || !is_array($r['json'])) return;
        if (TrackerWhitelistClient::consentEchoed($r['json'], $wanted)) {
            $this->settings->set(self::CONSENT_SENT, $wanted ? '1' : '0');
        } else {
            $this->store->write(self::CONSENT_TRY_KEY, ['wanted' => $wanted, 'at' => time()], 3600);
        }
    }

    private function logOnce(string $message): void
    {
        if (self::$logged) return;
        self::$logged = true;
        try { $this->logger->warning('[tryhackx-homepage-blocks] ' . $message); } catch (\Throwable $ignored) {}
    }
}
