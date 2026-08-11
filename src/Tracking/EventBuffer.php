<?php

namespace Spliteezy\Tracking;

use Spliteezy\Api\Client;

/**
 * Holds events the API could not accept, so an outage costs nothing.
 *
 * Before this existed a failed send simply dropped the batch: when the API was
 * unreachable for a day, that day vanished from every report with no trace and
 * no way to recover it.
 *
 * Retrying is only safe because each event carries an id assigned here rather
 * than by the API — a batch that half-landed can be sent again without
 * double-counting the part that arrived.
 */
class EventBuffer
{
    private const OPTION = 'spliteezy_event_buffer';

    /** Beyond this the oldest are dropped, so a long outage cannot fill the site's database. */
    private const MAX_EVENTS = 500;

    /** The API refuses events older than 7 days, so holding them past that is pointless. */
    private const MAX_AGE = 6 * DAY_IN_SECONDS;

    /** The API accepts 50 per call. */
    private const BATCH_SIZE = 50;

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public static function store(array $events): void
    {
        if (empty($events)) {
            return;
        }

        $pending = array_merge(self::pending(), array_values($events));

        // Newest wins: during a long outage the recent past is worth more than
        // a backlog that is about to be refused for age anyway.
        if (count($pending) > self::MAX_EVENTS) {
            $pending = array_slice($pending, -self::MAX_EVENTS);
        }

        self::save($pending);
    }

    /**
     * Send what is waiting, keeping anything that still will not go through.
     */
    public static function flush(): void
    {
        $pending = self::fresh(self::pending());

        if (empty($pending)) {
            self::save([]);

            return;
        }

        $client = new Client;
        $remaining = [];

        foreach (array_chunk($pending, self::BATCH_SIZE) as $batch) {
            // Stop at the first failure — the API is still down, and hammering
            // it with the rest of the backlog helps nobody.
            if ($remaining || ! $client->send_events($batch)) {
                $remaining = array_merge($remaining, $batch);
            }
        }

        self::save($remaining);
    }

    public static function count(): int
    {
        return count(self::pending());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function pending(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private static function fresh(array $events): array
    {
        $cutoff = time() - self::MAX_AGE;

        return array_values(array_filter(
            $events,
            static fn ($event) => (int) ($event['occurred_at'] ?? 0) >= $cutoff
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private static function save(array $events): void
    {
        if (empty($events)) {
            delete_option(self::OPTION);

            return;
        }

        // autoload off: this can hold hundreds of events and must never be
        // loaded on every page request.
        update_option(self::OPTION, $events, false);
    }
}
