<?php

namespace Spliteezy\Tracking;

use Spliteezy\Api\Client;

/**
 * Holds events the API could not accept, so an outage costs nothing.
 *
 * Before this existed a failed send dropped the batch: when the API was
 * unreachable for a day, that day vanished from every report with no trace and
 * no way to recover it.
 *
 * Kept in its own table rather than an option because an option serialises its
 * whole contents on every write — a busy site would be rewriting megabytes per
 * failed batch, so the practical ceiling would be a few hundred events, which
 * is under a day for even a modest site. Rows append and delete individually,
 * so what the queue holds is limited by age rather than by what the storage can
 * bear.
 *
 * Retrying is only safe because each event carries an id assigned by the
 * tracker rather than by the API: a batch that half-landed can be sent again
 * without double-counting the part that arrived.
 */
class EventBuffer
{
    /** Bumped whenever the table changes, so upgrades pick it up without reactivating. */
    private const SCHEMA_VERSION = 1;

    private const SCHEMA_OPTION = 'spliteezy_event_queue_version';

    /** The API refuses events older than 7 days, so holding them past that is pointless. */
    private const MAX_AGE = 6 * DAY_IN_SECONDS;

    /**
     * A backstop against filling the disk, not a retention policy — age does
     * the real work. Roughly a week of a very busy site.
     */
    private const MAX_ROWS = 200000;

    /** The API accepts 50 per call. */
    private const BATCH_SIZE = 50;

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'spliteezy_event_queue';
    }

    /**
     * Creates the table, and recreates it after an upgrade that changed it.
     */
    public static function install(): void
    {
        if ((int) get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;

        $table = self::table();
        $collate = $wpdb->get_charset_collate();

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event longtext NOT NULL,
                occurred_at bigint(20) unsigned NOT NULL,
                PRIMARY KEY  (id),
                KEY occurred_at (occurred_at)
            ) {$collate};"
        );

        // Autoloaded deliberately: it is a single int read on every request to
        // decide whether the table needs creating, and a non-autoloaded option
        // would make that a database query per page load.
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public static function store(array $events): void
    {
        if (empty($events)) {
            return;
        }

        global $wpdb;

        self::install();

        $table = self::table();
        $values = [];
        $params = [];

        foreach ($events as $event) {
            $encoded = wp_json_encode($event);

            if ($encoded === false) {
                continue;
            }

            $values[] = '(%s, %d)';
            $params[] = $encoded;
            $params[] = (int) ($event['occurred_at'] ?? time());
        }

        if (empty($values)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (event, occurred_at) VALUES ".implode(', ', $values), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $params
        ));

        self::prune();
    }

    /**
     * Send what is waiting, keeping anything that still will not go through.
     */
    public static function flush(): void
    {
        global $wpdb;

        if (! self::exists()) {
            return;
        }

        self::prune();

        $table = self::table();
        $client = new Client;

        // Bounded so a huge backlog cannot run past PHP's time limit; whatever
        // is left waits for the next run rather than blocking this request.
        for ($pass = 0; $pass < 20; $pass++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event FROM {$table} ORDER BY id ASC LIMIT %d", self::BATCH_SIZE));

            if (empty($rows)) {
                return;
            }

            $events = [];
            $ids = [];

            foreach ($rows as $row) {
                $decoded = json_decode($row->event, true);

                // Unreadable rows are dropped rather than retried forever.
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }

                $ids[] = (int) $row->id;
            }

            // Still unreachable — leave the rest for the next attempt.
            if ($events && ! $client->send_events($events)) {
                return;
            }

            self::deleteIds($ids);
        }
    }

    public static function count(): int
    {
        global $wpdb;

        if (! self::exists()) {
            return 0;
        }

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function uninstall(): void
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        delete_option(self::SCHEMA_OPTION);

        // The option this queue briefly used before moving to a table.
        delete_option('spliteezy_event_buffer');
    }

    /**
     * Drop what the API would refuse for age, then enforce the disk backstop.
     */
    private static function prune(): void
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE occurred_at < %d", time() - self::MAX_AGE));

        $excess = self::count() - self::MAX_ROWS;

        if ($excess <= 0) {
            return;
        }

        // Oldest first: they are closest to being refused for age anyway.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $excess));
    }

    /**
     * @param  array<int, int>  $ids
     */
    private static function deleteIds(array $ids): void
    {
        global $wpdb;

        if (empty($ids)) {
            return;
        }

        $table = self::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));
    }

    private static function exists(): bool
    {
        return (int) get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION;
    }
}
