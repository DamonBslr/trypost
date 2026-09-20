<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed status for an AI post creation so the loading page can resume
 * after a reload or a missed Reverb event. Keyed per user + creation id.
 *
 * @phpstan-type Status array{state: string, post_id: string|null, error: string|null}
 */
final class PostCreationStatus
{
    public const string STATE_PENDING = 'pending';

    public const string STATE_COMPLETED = 'completed';

    public const string STATE_FAILED = 'failed';

    public const int TTL_SECONDS = 3600;

    public static function key(string $userId, string $creationId): string
    {
        return "ai-post-creation:{$userId}:{$creationId}";
    }

    public static function markPending(string $userId, string $creationId): void
    {
        if (self::isTerminal($userId, $creationId)) {
            return;
        }

        self::put($userId, $creationId, [
            'state' => self::STATE_PENDING,
            'post_id' => null,
            'error' => null,
        ]);
    }

    public static function markCompleted(string $userId, string $creationId, string $postId): void
    {
        self::put($userId, $creationId, [
            'state' => self::STATE_COMPLETED,
            'post_id' => $postId,
            'error' => null,
        ]);
    }

    public static function markFailed(string $userId, string $creationId, string $error): void
    {
        if (self::state($userId, $creationId) === self::STATE_COMPLETED) {
            return;
        }

        self::put($userId, $creationId, [
            'state' => self::STATE_FAILED,
            'post_id' => null,
            'error' => $error,
        ]);
    }

    /**
     * @return Status|null
     */
    public static function get(string $userId, string $creationId): ?array
    {
        $value = Cache::get(self::key($userId, $creationId));

        if (! is_array($value)) {
            return null;
        }

        $state = data_get($value, 'state');

        if (! is_string($state) || $state === '') {
            return null;
        }

        $postId = data_get($value, 'post_id');
        $error = data_get($value, 'error');

        return [
            'state' => $state,
            'post_id' => is_string($postId) && $postId !== '' ? $postId : null,
            'error' => is_string($error) && $error !== '' ? $error : null,
        ];
    }

    public static function state(string $userId, string $creationId): ?string
    {
        return data_get(self::get($userId, $creationId), 'state');
    }

    public static function isTerminal(string $userId, string $creationId): bool
    {
        return in_array(self::state($userId, $creationId), [self::STATE_COMPLETED, self::STATE_FAILED], true);
    }

    /**
     * @param  Status  $status
     */
    private static function put(string $userId, string $creationId, array $status): void
    {
        Cache::put(self::key($userId, $creationId), $status, self::TTL_SECONDS);
    }
}
