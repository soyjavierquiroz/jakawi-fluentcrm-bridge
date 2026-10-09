<?php

namespace Jakawi\FluentCrmBridge;

use FluentCrm\App\Models\Subscriber;

final class Adapter
{
    public function upsert(array $v): int
    {
        $map = (new SchemaManager)->map();
        $email = $v['contact']['email'];
        $uid = $v['identity']['jakawi_user_id'] ?? null;
        $pid = $v['identity']['provider_contact_id'] ?? null;
        $byEmail = FluentCrmApi('contacts')->getContact($email);
        $byUser = $uid ? Subscriber::whereHas('custom_field_meta', fn ($q) => $q->where('key', 'jakawi_user_id')->where('value', (string) $uid))->get() : null;
        if ($byUser && $byUser->count() > 1) {
            throw new BridgeError('IDENTITY_CONFLICT', 409);
        }
        $contact = $pid ? FluentCrmApi('contacts')->getContact((int) $pid) : ($byUser && $byUser->count() ? $byUser[0] : $byEmail);
        if ($pid && ! $contact) {
            throw new BridgeError('CONTACT_LINK_MISSING', 409);
        }
        if ($contact && (($byEmail && (int) $byEmail->id !== (int) $contact->id) || ($byUser && $byUser->count() && (int) $byUser[0]->id !== (int) $contact->id))) {
            throw new BridgeError('IDENTITY_CONFLICT', 409);
        }
        $owned = $contact ? $contact->custom_fields() : [];
        if ($contact && $uid && ! empty($owned['jakawi_user_id']) && (string) $owned['jakawi_user_id'] !== (string) $uid) {
            throw new BridgeError('IDENTITY_CONFLICT', 409);
        }
        if (isset($v['fields']['jakawi_user_id']) && (string) $v['fields']['jakawi_user_id'] !== (string) $uid) {
            throw new BridgeError('IDENTITY_CONFLICT', 409);
        }
        $state = $contact ? ($contact->getMeta('jakawi_bridge_state', 'jakawi_bridge') ?: []) : [];
        $fields = $v['fields'];
        $incoming = [Contract::clock($v['occurred_at']), $v['event_id']];
        $guestOnUser = ! $uid && ! empty($owned['jakawi_user_id']);
        // Product identity promotion outranks a later guest-only snapshot.
        $promotingUser = $uid && empty($owned['jakawi_user_id']);
        $newer = ! $guestOnUser && ($promotingUser || ! isset($state['domain']) || $incoming > $state['domain']);
        if (! $newer && $contact && $contact->email !== $email) {
            $v['contact']['email'] = $contact->email;
            $email = $contact->email;
        }
        foreach (['first', 'last'] as $prefix) {
            $snapshot = $v['source'][$prefix.'_attribution'] ?? null;
            $clock = $snapshot ? [Contract::clock($snapshot['occurred_at']), $snapshot['id']] : null;
            $apply = $prefix === 'first' ? ! isset($state['first']) : ($clock && (! isset($state['last']) || $clock > $state['last']));
            // An existing nonempty first snapshot is treated as already owned.
            if ($prefix === 'first' && array_filter($owned, fn ($value, $key) => str_starts_with($key, 'first_') && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH)) {
                $apply = false;
            }
            if (! $apply) {
                foreach (array_keys($fields) as $k) {
                    if (str_starts_with($k, $prefix.'_') && ! in_array($k, ['last_activity_at', 'last_activity_type'], true)) {
                        unset($fields[$k]);
                    }
                }
            } elseif ($clock) {
                $state[$prefix] = $clock;
            }
        }
        foreach (['lead_source', 'lead_form', 'signup_at'] as $key) {
            if (isset($owned[$key]) && $owned[$key] !== '') {
                unset($fields[$key]);
            }
        }
        // Acquisition tags always follow the frozen FIRST snapshot.
        $provider = $owned['first_acquisition_provider'] ?? ($fields['first_acquisition_provider'] ?? 'NONE');
        $v['tags_add'] = array_values(array_filter($v['tags_add'], fn ($tag) => ! str_starts_with($tag, 'source-')));
        $v['tags_remove'] = array_values(array_filter($v['tags_remove'], fn ($tag) => ! str_starts_with($tag, 'source-')));
        $sourceTag = ['META' => 'source-meta', 'TIKTOK' => 'source-tiktok', 'GOOGLE' => 'source-google'][$provider] ?? null;
        if ($sourceTag) {
            $v['tags_add'][] = $sourceTag;
        }
        if (! $newer) {
            foreach (['jakawi_city', 'jakawi_lifecycle_stage', 'membership_status', 'membership_started_at', 'membership_expires_at'] as $key) {
                unset($fields[$key]);
            }
        }
        if (isset($fields['last_activity_at']) && Contract::clock($fields['last_activity_at']) <= ($state['activity'] ?? (isset($owned['last_activity_at']) ? (float) (new \DateTimeImmutable($owned['last_activity_at'], new \DateTimeZone('UTC')))->format('U.u') : 0))) {
            unset($fields['last_activity_at'], $fields['last_activity_type']);
        } elseif (isset($fields['last_activity_at'])) {
            $state['activity'] = Contract::clock($fields['last_activity_at']);
        }
        $action = $v['marketing']['action'];
        $consentClock = isset($v['fields']['marketing_opt_in_at']) ? [Contract::clock($v['fields']['marketing_opt_in_at']), Contract::clock($v['occurred_at']), $v['event_id']] : [Contract::clock($v['occurred_at']), Contract::clock($v['occurred_at']), $v['event_id']];
        if (isset($state['consent']) && $consentClock <= $state['consent']) {
            $action = 'UNCHANGED';
            foreach (['marketing_opt_in', 'marketing_opt_in_at', 'marketing_opt_in_source'] as $key) {
                unset($fields[$key]);
            }
        } elseif ($action !== 'UNCHANGED') {
            $state['consent'] = $consentClock;
        }
        if ($action === 'UNCHANGED') {
            foreach (['marketing_opt_in', 'marketing_opt_in_at', 'marketing_opt_in_source'] as $key) {
                unset($fields[$key]);
            }
        }
        $status = Contract::subscription($contact ? $contact->status : null, $action);
        if (! $contact) {
            $contact = FluentCrmApi('contacts')->createOrUpdate(['email' => $email, 'full_name' => $v['contact']['name'] ?? '', 'status' => $status], false, false);
            if (! $contact) {
                throw new BridgeError('PROVIDER_ERROR', 503);
            }
        } else {
            // Resolve by durable identity before changing email. Never updateOrCreate by a changed email.
            if ($newer) {
                $old = $contact->email;
                $contact->email = $email;
                if (isset($v['contact']['name'])) {
                    $name = Subscriber::explodeFullName(['full_name' => $v['contact']['name']]);
                    $contact->first_name = $name['first_name'] ?? '';
                    $contact->last_name = $name['last_name'] ?? '';
                }
                if ($contact->save() === false) {
                    throw new BridgeError('PROVIDER_ERROR', 503);
                }
                if ($old !== $email) {
                    do_action('fluent_crm/contact_email_changed', $contact, $old);
                }
            }
            if ($status !== $contact->status) {
                $contact->updateStatus($status);
            }
        }
        if ($uid) {
            $fields['jakawi_user_id'] = (string) $uid;
        }
        foreach ($fields as $k => $value) {
            if ($value === null) {
                $fields[$k] = '';
            } elseif (is_bool($value)) {
                $fields[$k] = $value ? '1' : '0';
            } elseif (str_ends_with($k, '_at') || in_array($k, ['membership_started_at', 'membership_expires_at'], true)) {
                $fields[$k] = gmdate('Y-m-d H:i:s', strtotime($value));
            }
        }
        $contact->syncCustomFieldValues($fields, true);
        if (! $newer) {
            $history = array_intersect($v['tags_add'], ['jakawi-lead', 'partner-applicant', 'program-applicant', 'benefit-redeemer', 'experience-reserver', 'challenge-participant', 'unlock-participant', 'source-meta', 'source-tiktok', 'source-google']);
            if ($guestOnUser) {
                $history = array_diff($history, ['jakawi-lead']);
            }
            if ($history) {
                $contact->attachTags(array_map(fn ($k) => $map['tags'][$k], $history));
            }
        }
        if ($newer) {
            foreach (['lists', 'tags'] as $kind) {
                $remove = array_map(fn ($k) => $map[$kind][$k], $v[$kind.'_remove']);
                $add = array_map(fn ($k) => $map[$kind][$k], $v[$kind.'_add']);
                $detach = $kind === 'lists' ? 'detachLists' : 'detachTags';
                $attach = $kind === 'lists' ? 'attachLists' : 'attachTags';
                $has = $kind === 'lists' ? 'hasAnyListId' : 'hasAnyTagId';
                if ($remove) {
                    $contact->$detach($remove);
                } if ($add) {
                    $contact->$attach($add);
                }
                foreach ($add as $entityId) {
                    if (! $contact->$has([$entityId])) {
                        throw new BridgeError('PROVIDER_ENTITY_ERROR', 503);
                    }
                }
                foreach ($remove as $entityId) {
                    if ($contact->$has([$entityId])) {
                        throw new BridgeError('PROVIDER_ENTITY_ERROR', 503);
                    }
                }
            }
            $state['domain'] = $incoming;
        }
        $contact->updateMeta('jakawi_bridge_state', $state, 'jakawi_bridge');

        return (int) $contact->id;
    }
}
