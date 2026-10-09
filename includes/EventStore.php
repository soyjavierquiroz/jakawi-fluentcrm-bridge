<?php

namespace Jakawi\FluentCrmBridge;

final class EventStore
{
    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'jakawi_fluentcrm_bridge_events';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            event_id char(36) NOT NULL,
            body_hash char(64) NOT NULL,
            status varchar(20) NOT NULL,
            result_code varchar(64) NOT NULL,
            provider_contact_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            processed_at datetime NULL,
            PRIMARY KEY  (event_id)
        ) ENGINE=InnoDB $charset;");
    }

    public static function decision(?array $row, string $hash): string
    {
        if (! $row) {
            return 'RESERVE';
        } if (! hash_equals($row['body_hash'], $hash)) {
            return 'CONFLICT';
        }

        return match ($row['status']) {
            'SENT' => 'SENT','PROCESSING' => 'PROCESSING','RETRY' => 'RESERVE',default => 'DEAD'
        };
    }

    public function process(array $v, string $hash, callable $mutation): array
    {
        global $wpdb;
        $t = self::table();
        $id = $v['event_id'];
        $now = gmdate('Y-m-d H:i:s');
        // Authenticated callers only. Global serialization also protects email/user identity
        // and contact clocks across distinct event IDs. No lock stealing while a writer lives.
        if (! $wpdb->get_var("SELECT GET_LOCK('jakawi_fluentcrm_writer', 0)")) {
            throw new BridgeError('IN_PROGRESS', 503);
        }
        $previousErrors = $wpdb->suppress_errors(true);
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE event_id=%s", $id), ARRAY_A);
            $decision = self::decision($row, $hash);
            if ($decision === 'CONFLICT') {
                throw new BridgeError('EVENT_BODY_CONFLICT', 409);
            }
            if ($decision === 'SENT') {
                return ['provider_contact_id' => (int) $row['provider_contact_id'], 'idempotent' => true];
            }
            if ($decision === 'DEAD') {
                throw new BridgeError($row['result_code'], 409);
            }
            // The named writer lock guarantees an old PROCESSING owner has exited.
            // Recover a crashed transaction without overlapping mutations.
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new BridgeError('STORE_UNAVAILABLE', 503);
            }
            if (! $row) {
                $ok = $wpdb->query($wpdb->prepare("INSERT INTO $t (event_id,body_hash,status,result_code,created_at,updated_at) VALUES (%s,%s,'PROCESSING','RESERVED',%s,%s)", $id, $hash, $now, $now));
            } else {
                $ok = $wpdb->update($t, ['status' => 'PROCESSING', 'updated_at' => $now], ['event_id' => $id, 'body_hash' => $hash]);
            }
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                throw new BridgeError('STORE_UNAVAILABLE', 503);
            }
            try {
                $pid = $mutation($v);
                if ($wpdb->update($t, ['status' => 'SENT', 'result_code' => 'OK', 'provider_contact_id' => $pid, 'processed_at' => $now, 'updated_at' => $now], ['event_id' => $id]) === false) {
                    throw new BridgeError('STORE_UNAVAILABLE', 503);
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new BridgeError('STORE_UNAVAILABLE', 503);
                }

                return ['provider_contact_id' => $pid, 'idempotent' => false];
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                $code = $e instanceof BridgeError ? $e->resultCode : 'PROVIDER_ERROR';
                $http = $e instanceof BridgeError ? $e->httpStatus : 503;
                $wpdb->query($wpdb->prepare("INSERT INTO $t (event_id,body_hash,status,result_code,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE status=VALUES(status),result_code=VALUES(result_code),updated_at=VALUES(updated_at)", $id, $hash, $http >= 500 ? 'RETRY' : 'DEAD', $code, $now, $now));
                throw new BridgeError($code, $http);
            }
        } finally {
            $wpdb->get_var("SELECT RELEASE_LOCK('jakawi_fluentcrm_writer')");
            $wpdb->suppress_errors($previousErrors);
        }
    }
}
