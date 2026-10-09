<?php

namespace Jakawi\FluentCrmBridge;

use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;

final class SchemaManager
{
    const VERSION = Contract::CRM_SCHEMA_VERSION;

    private ?array $diagnostic = null;

    private function conflict(string $kind, string $key, string $reason): never
    {
        $code = 'SCHEMA_'.$kind.'_CONFLICT';
        $this->diagnostic = ['entity_kind' => $kind, 'contract_key' => $key, 'error_code' => $code, 'reason' => $reason];
        throw new BridgeError($code, 409);
    }

    private function type(string $key): string
    {
        return $key === 'jakawi_user_id' ? 'number' : (str_ends_with($key, '_at') ? 'date_time' : 'text');
    }

    // Live definitions are authoritative. Saved IDs are only a cache, never a
    // prerequisite for resolving an entity or a reason to reject recreation.
    private function inspect(): array
    {
        $this->diagnostic = null;
        $map = ['lists' => [], 'tags' => [], 'fields' => []];
        foreach (['lists' => Contract::LISTS, 'tags' => array_combine(Contract::TAGS, Contract::TAGS)] as $kind => $items) {
            $class = $kind === 'lists' ? Lists::class : Tag::class;
            $entityKind = $kind === 'lists' ? 'LIST' : 'TAG';
            foreach ($items as $key => $title) {
                $slug = 'jakawi-v1-'.$key;
                $matches = $class::where('slug', $slug)->orWhere('title', $title)->get();
                if ($matches->count() > 1) {
                    $this->conflict($entityKind, $key, 'AMBIGUOUS_LIVE_MATCHES');
                }
                if ($matches->count() === 1 && $matches[0]->slug !== $slug) {
                    $this->conflict($entityKind, $key, 'CONTRACT_TITLE_WITH_DIFFERENT_SLUG');
                }
                // Display titles may change without changing stable identity.
                $map[$kind][$key] = $matches->count() ? (int) $matches[0]->id : null;
            }
        }
        $fields = (new CustomContactField)->getGlobalFields()['fields'];
        foreach (Contract::FIELDS as $key) {
            $matches = array_values(array_filter($fields, fn ($f) => ($f['slug'] ?? '') === $key || ($f['label'] ?? '') === 'JAKAWI '.$key));
            if (count($matches) > 1) {
                $this->conflict('FIELD', $key, 'AMBIGUOUS_LIVE_MATCHES');
            }
            if ($matches && ($matches[0]['slug'] ?? '') !== $key) {
                $this->conflict('FIELD', $key, 'CONTRACT_LABEL_WITH_DIFFERENT_SLUG');
            }
            if ($matches && ($matches[0]['type'] ?? '') !== $this->type($key)) {
                $this->conflict('FIELD', $key, 'INCOMPATIBLE_FIELD_TYPE');
            }
            // FluentCRM fields have slug identity, not numeric entity IDs.
            $map['fields'][$key] = $matches ? $this->type($key) : null;
        }

        return $map;
    }

    private function complete(array $map): bool
    {
        foreach ($map as $entries) {
            if (in_array(null, $entries, true)) {
                return false;
            }
        }
        return true;
    }

    public function status(): array
    {
        $saved = get_option('jakawi_fluentcrm_schema', []);
        $data = ['status' => 'NOT PROVISIONED', 'lists' => 0, 'tags' => 0, 'custom_fields' => 0,
            'expected' => ['lists' => count(Contract::LISTS), 'tags' => count(Contract::TAGS), 'custom_fields' => count(Contract::FIELDS)]];
        try {
            $map = $this->inspect();
            foreach (['lists' => 'lists', 'tags' => 'tags', 'fields' => 'custom_fields'] as $kind => $label) {
                $data[$label] = count(array_filter($map[$kind], fn ($value) => $value !== null));
            }
            if ($saved || array_sum([$data['lists'], $data['tags'], $data['custom_fields']])) {
                $data['status'] = 'DRIFT';
                if ($this->complete($map) && ($saved['version'] ?? 0) === self::VERSION && ($saved['status'] ?? '') === 'READY' && ($saved['map'] ?? []) === $map) {
                    $data['status'] = 'READY';
                } elseif ($this->complete($map) && ($saved['status'] ?? '') === 'SCHEMA_ERROR') {
                    $data['status'] = 'ERROR';
                }
            }
        } catch (BridgeError $e) {
            $data['status'] = $this->diagnostic ? 'CONFLICT' : 'ERROR';
            if ($this->diagnostic) {
                $data['diagnostic'] = $this->diagnostic;
            }
        } catch (\Throwable) {
            $data['status'] = 'ERROR';
        }

        return $data;
    }

    public function ensure(): void
    {
        global $wpdb;
        if (! $wpdb->get_var("SELECT GET_LOCK('jakawi_fluentcrm_schema', 0)")) {
            throw new BridgeError('SCHEMA_BUSY', 503);
        }
        $saved = get_option('jakawi_fluentcrm_schema', []);
        try {
            // A complete live preflight precedes every write. Resolving by slug
            // discards stale saved IDs and adopts compatible replacement IDs.
            $map = $this->inspect();
            foreach (['lists' => Contract::LISTS, 'tags' => array_combine(Contract::TAGS, Contract::TAGS)] as $kind => $items) {
                foreach ($items as $key => $title) {
                    if ($map[$kind][$key] === null) {
                        $class = $kind === 'lists' ? Lists::class : Tag::class;
                        $entity = $class::create(['title' => $title, 'slug' => 'jakawi-v1-'.$key]);
                        if (! $entity || ! $entity->id) {
                            throw new BridgeError('SCHEMA_WRITE_FAILED', 503);
                        }
                        $map[$kind][$key] = (int) $entity->id;
                    }
                }
            }
            $manager = new CustomContactField;
            $fields = $manager->getGlobalFields()['fields'];
            foreach (Contract::FIELDS as $key) {
                if ($map['fields'][$key] === null) {
                    $fields[] = ['slug' => $key, 'label' => 'JAKAWI '.$key, 'type' => $this->type($key), 'group' => 'default'];
                }
                $map['fields'][$key] = $this->type($key);
            }
            $manager->saveGlobalFields($fields);
            // Persist a candidate for validation, but do not report READY until
            // every stored entity resolves to the exact live contract.
            update_option('jakawi_fluentcrm_schema', ['version' => self::VERSION, 'status' => 'VALIDATING', 'map' => $map], false);
            $this->validateMap($map);
            update_option('jakawi_fluentcrm_schema', ['version' => self::VERSION, 'status' => 'READY', 'map' => $map], false);
            $this->map();
        } catch (\Throwable $e) {
            $failure = ['version' => self::VERSION, 'status' => $this->diagnostic ? $this->diagnostic['error_code'] : 'SCHEMA_ERROR', 'map' => $map ?? ($saved['map'] ?? [])];
            if ($this->diagnostic) {
                $failure['diagnostic'] = $this->diagnostic;
            }
            update_option('jakawi_fluentcrm_schema', $failure, false);
            throw $e;
        } finally {
            $wpdb->get_var("SELECT RELEASE_LOCK('jakawi_fluentcrm_schema')");
        }
    }

    private function validateMap(array $map): void
    {
        $live = $this->inspect();
        if (! $this->complete($live) || $map !== $live) {
            throw new BridgeError('SCHEMA_DRIFT', 503);
        }
    }

    public function map(): array
    {
        $saved = get_option('jakawi_fluentcrm_schema', []);
        if (($saved['version'] ?? 0) !== self::VERSION || ($saved['status'] ?? '') !== 'READY') {
            throw new BridgeError('SCHEMA_NOT_READY', 503);
        }
        $map = $saved['map'] ?? [];
        $this->validateMap($map);
        return $map;
    }
}
