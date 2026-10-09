<?php

namespace Jakawi\FluentCrmBridge;

final class Contract
{
    const CRM_SCHEMA_VERSION = 1;

    const ACTIVITIES = ['lead_submitted', 'signup_completed', 'profile_updated', 'marketing_preference_changed', 'membership_purchase_requested', 'membership_activated', 'benefit_redeemed', 'experience_reserved', 'challenge_joined', 'unlock_committed', 'partner_application_submitted', 'program_application_submitted'];

    const LIMIT = 65536;

    const LISTS = ['leads' => 'JAKAWI — Leads', 'users' => 'JAKAWI — Usuarios', 'partners' => 'JAKAWI — Partners'];

    const TAGS = ['jakawi-lead', 'jakawi-user', 'partner-applicant', 'program-applicant', 'jakawi-partner', 'membership-requested', 'member-active', 'member-expired', 'benefit-redeemer', 'experience-reserver', 'challenge-participant', 'unlock-participant', 'source-meta', 'source-tiktok', 'source-google'];

    const FIELDS = ['jakawi_user_id', 'jakawi_city', 'jakawi_lifecycle_stage', 'membership_status', 'membership_started_at', 'membership_expires_at', 'lead_source', 'lead_form', 'first_acquisition_provider', 'first_utm_source', 'first_utm_medium', 'first_utm_campaign', 'first_utm_content', 'first_utm_term', 'first_campaign_key', 'first_landing_slug', 'first_touch_at', 'last_acquisition_provider', 'last_utm_source', 'last_utm_medium', 'last_utm_campaign', 'last_utm_content', 'last_utm_term', 'last_campaign_key', 'last_landing_slug', 'last_touch_at', 'signup_at', 'last_activity_type', 'last_activity_at', 'marketing_opt_in', 'marketing_opt_in_at', 'marketing_opt_in_source'];

    public static function uuid($v): bool
    {
        return is_string($v) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $v);
    }

    public static function authenticate(string $body, array $h, string $secret, int $now): void
    {
        if (strlen($body) > self::LIMIT) {
            throw new BridgeError('BODY_TOO_LARGE', 413);
        }
        if (strlen($secret) < 32 || ! ctype_digit($h['timestamp'] ?? '') || abs($now - (int) $h['timestamp']) > 300) {
            throw new BridgeError('AUTH_FAILED', 401);
        }
        if (! self::uuid($h['event_id'] ?? null)) {
            throw new BridgeError('AUTH_FAILED', 401);
        }
        $expected = hash_hmac('sha256', $h['timestamp']."\n".$h['event_id']."\n".$body, $secret);
        if (! hash_equals($expected, $h['signature'] ?? '')) {
            throw new BridgeError('AUTH_FAILED', 401);
        }
    }

    public static function validate(string $body, string $event): array
    {
        $v = json_decode($body, true);
        $keys = ['version', 'event_id', 'operation', 'occurred_at', 'source', 'contact', 'identity', 'lists_add', 'lists_remove', 'tags_add', 'tags_remove', 'fields', 'marketing'];
        if (! is_array($v) || array_diff(array_keys($v), $keys) || array_diff($keys, array_keys($v))) {
            throw new BridgeError('INVALID_ENVELOPE', 422);
        }
        if ($v['version'] !== 1 || $v['operation'] !== 'CONTACT_UPSERT' || ! self::uuid($v['event_id']) || $event !== $v['event_id']) {
            throw new BridgeError('INVALID_CONTRACT', 422);
        }
        self::time($v['occurred_at']);
        foreach (['source', 'contact', 'identity', 'fields', 'marketing'] as $k) {
            if (! is_array($v[$k])) {
                throw new BridgeError('INVALID_CONTRACT', 422);
            }
        }
        self::only($v['source'], ['type', 'id', 'first_attribution', 'last_attribution']);
        if (! is_string($v['source']['type'] ?? null) || ! is_scalar($v['source']['id'] ?? null)) {
            throw new BridgeError('INVALID_SOURCE', 422);
        }
        if (! in_array($v['source']['type'], [...self::ACTIVITIES, 'membership_updated'], true)) {
            throw new BridgeError('INVALID_SOURCE', 422);
        }
        foreach (['first_attribution', 'last_attribution'] as $k) {
            if (isset($v['source'][$k])) {
                self::only($v['source'][$k], ['occurred_at', 'id']);
                self::time($v['source'][$k]['occurred_at'] ?? null);
                if (! is_int($v['source'][$k]['id'] ?? null)) {
                    throw new BridgeError('INVALID_SOURCE', 422);
                }
            }
        }
        self::only($v['contact'], ['email', 'name']);
        self::only($v['identity'], ['jakawi_user_id', 'provider_contact_id']);
        self::only($v['marketing'], ['action']);
        if (! is_string($v['contact']['email'] ?? null) || ! filter_var($v['contact']['email'], FILTER_VALIDATE_EMAIL) || strlen($v['contact']['email']) > 254) {
            throw new BridgeError('INVALID_EMAIL', 422);
        }
        if (isset($v['contact']['name']) && (! is_string($v['contact']['name']) || strlen($v['contact']['name']) > 255)) {
            throw new BridgeError('INVALID_CONTACT', 422);
        }
        foreach ($v['identity'] as $id) {
            if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
                throw new BridgeError('INVALID_IDENTITY', 422);
            }
        }
        if (! in_array($v['marketing']['action'] ?? null, ['GRANT', 'REVOKE', 'UNCHANGED'], true)) {
            throw new BridgeError('INVALID_MARKETING', 422);
        }
        foreach (['lists_add', 'lists_remove', 'tags_add', 'tags_remove'] as $k) {
            $allowed = str_starts_with($k, 'lists') ? array_keys(self::LISTS) : self::TAGS;
            if (! is_array($v[$k]) || ! array_is_list($v[$k]) || count(array_filter($v[$k], 'is_string')) !== count($v[$k]) || array_diff($v[$k], $allowed)) {
                throw new BridgeError('UNKNOWN_ENTITY', 422);
            }
        }
        if (array_intersect($v['lists_add'], $v['lists_remove']) || array_intersect($v['tags_add'], $v['tags_remove'])) {
            throw new BridgeError('CONTRADICTORY_ENTITY', 422);
        }
        self::only($v['fields'], self::FIELDS);
        foreach ($v['fields'] as $value) {
            if ($value !== null && (! is_scalar($value) || strlen((string) $value) > 2048)) {
                throw new BridgeError('INVALID_FIELD', 422);
            }
        }
        foreach ($v['fields'] as $key => $value) {
            if ($value !== null && str_ends_with($key, '_at')) {
                self::time($value);
            }
            if (str_ends_with($key, 'acquisition_provider') && ! in_array($value, ['META', 'TIKTOK', 'GOOGLE', 'NONE'], true)) {
                throw new BridgeError('INVALID_PROVIDER', 422);
            }
        }
        if (isset($v['fields']['marketing_opt_in']) && ! is_bool($v['fields']['marketing_opt_in'])) {
            throw new BridgeError('INVALID_MARKETING', 422);
        }
        if ($v['marketing']['action'] !== 'UNCHANGED' && (! isset($v['fields']['marketing_opt_in_at']) || ! array_key_exists('marketing_opt_in', $v['fields']) || $v['fields']['marketing_opt_in'] !== ($v['marketing']['action'] === 'GRANT'))) {
            throw new BridgeError('INVALID_MARKETING', 422);
        }
        foreach (['first', 'last'] as $prefix) {
            $snapshot = array_filter(array_keys($v['fields']), fn ($k) => str_starts_with($k, $prefix.'_') && ! in_array($k, ['last_activity_at', 'last_activity_type'], true));
            if ($snapshot && (count($snapshot) !== 9 || ! isset($v['source'][$prefix.'_attribution']))) {
                throw new BridgeError('INCOHERENT_ATTRIBUTION', 422);
            }
        }
        if (isset($v['fields']['last_activity_type']) && ! in_array($v['fields']['last_activity_type'], self::ACTIVITIES, true)) {
            throw new BridgeError('INVALID_ACTIVITY', 422);
        }
        foreach (['first', 'last'] as $prefix) {
            if (isset($v['fields'][$prefix.'_touch_at']) && self::clock($v['fields'][$prefix.'_touch_at']) !== self::clock($v['source'][$prefix.'_attribution']['occurred_at'])) {
                throw new BridgeError('INCOHERENT_ATTRIBUTION', 422);
            }
        }
        $v['contact']['email'] = strtolower(trim($v['contact']['email']));

        return $v;
    }

    public static function only(array $v, array $keys): void
    {
        if (array_diff(array_keys($v), $keys)) {
            throw new BridgeError('UNKNOWN_KEY', 422);
        }
    }

    public static function time($v): void
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/D', $v) || strtotime($v) === false) {
            throw new BridgeError('INVALID_TIMESTAMP', 422);
        }
    }

    public static function clock(string $value): float
    {
        return (float) (new \DateTimeImmutable($value))->format('U.u');
    }

    public static function subscription(?string $existing, string $action): string
    {
        if (in_array($existing, ['unsubscribed', 'bounced', 'complained', 'spammed'], true)) {
            return $existing;
        }
        if (! $existing) {
            return $action === 'GRANT' ? 'subscribed' : 'transactional';
        }
        if ($action === 'REVOKE') {
            return 'unsubscribed';
        }
        if ($action === 'GRANT' && $existing === 'transactional') {
            return 'subscribed';
        }

        return $existing;
    }
}
