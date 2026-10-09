<?php

namespace Jakawi\FluentCrmBridge;

use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;

final class SchemaManager
{
    const VERSION = Contract::CRM_SCHEMA_VERSION;

    public function status(): array
    {
        $saved = get_option('jakawi_fluentcrm_schema', []);
        $status = 'NOT PROVISIONED';
        if ($saved) {
            try {
                $this->map();
                $status = 'READY';
            } catch (BridgeError $e) {
                $status = str_contains($saved['status'] ?? '', 'CONFLICT') ? 'CONFLICT' : ($e->resultCode === 'SCHEMA_DRIFT' ? 'DRIFT' : 'ERROR');
            } catch (\Throwable) {
                $status = 'ERROR';
            }
        }
        return ['status' => $status, 'lists' => count($saved['map']['lists'] ?? []), 'tags' => count($saved['map']['tags'] ?? []), 'custom_fields' => count($saved['map']['fields'] ?? [])];
    }

    public function ensure(): void
    {
        global $wpdb;
        if (! $wpdb->get_var("SELECT GET_LOCK('jakawi_fluentcrm_schema', 0)")) {
            throw new BridgeError('SCHEMA_BUSY', 503);
        }
        try {
            $map = ['lists' => [], 'tags' => [], 'fields' => []];
            // Preflight all conflicts before creating anything.
            foreach (['lists' => Contract::LISTS, 'tags' => array_combine(Contract::TAGS, Contract::TAGS)] as $kind => $items) {
                foreach ($items as $key => $title) {
                    $class = $kind === 'lists' ? Lists::class : Tag::class;
                    $slug = 'jakawi-v1-'.$key;
                    $matches = $class::where('slug', $slug)->orWhere('title', $title)->get();
                    if ($matches->count() > 1 || ($matches->count() === 1 && ($matches[0]->slug !== $slug || $matches[0]->title !== $title))) {
                        throw new BridgeError('SCHEMA_CONFLICT', 409);
                    }
                    $map[$kind][$key] = $matches->count() ? (int) $matches[0]->id : null;
                }
            }
            $manager = new CustomContactField;
            $existing = $manager->getGlobalFields()['fields'];
            foreach (Contract::FIELDS as $key) {
                $type = $this->type($key);
                $matches = array_values(array_filter($existing, fn ($f) => $f['slug'] === $key || $f['label'] === 'JAKAWI '.$key));
                if (count($matches) > 1 || ($matches && ($matches[0]['slug'] !== $key || $matches[0]['type'] !== $type || $matches[0]['label'] !== 'JAKAWI '.$key))) {
                    throw new BridgeError('SCHEMA_FIELD_CONFLICT', 409);
                }
                $map['fields'][$key] = $type;
            }
            foreach (['lists' => Contract::LISTS, 'tags' => array_combine(Contract::TAGS, Contract::TAGS)] as $kind => $items) {
                foreach ($items as $key => $title) {
                    if (! $map[$kind][$key]) {
                        $class = $kind === 'lists' ? Lists::class : Tag::class;
                        $entity = $class::create(['title' => $title, 'slug' => 'jakawi-v1-'.$key]);
                        $map[$kind][$key] = (int) $entity->id;
                    }
                }
            }
            foreach (Contract::FIELDS as $key) {
                if (! array_filter($existing, fn ($f) => $f['slug'] === $key)) {
                    $existing[] = ['slug' => $key, 'label' => 'JAKAWI '.$key, 'type' => $this->type($key), 'group' => 'default'];
                }
            }
            $manager->saveGlobalFields($existing);
            update_option('jakawi_fluentcrm_schema', ['version' => self::VERSION, 'status' => 'READY', 'map' => $map], false);
        } catch (\Throwable $e) {
            update_option('jakawi_fluentcrm_schema', ['version' => 0, 'status' => $e instanceof BridgeError ? $e->resultCode : 'SCHEMA_ERROR'], false);
            throw $e;
        } finally {
            $wpdb->get_var("SELECT RELEASE_LOCK('jakawi_fluentcrm_schema')");
        }
    }

    private function type(string $key): string
    {
        return $key === 'jakawi_user_id' ? 'number' : (str_ends_with($key, '_at') ? 'date_time' : 'text');
    }

    public function map(): array
    {
        $s = get_option('jakawi_fluentcrm_schema', []);
        if (($s['version'] ?? 0) !== self::VERSION || ($s['status'] ?? '') !== 'READY') {
            throw new BridgeError('SCHEMA_NOT_READY', 503);
        }

        $map = $s['map'];
        foreach (['lists' => Contract::LISTS, 'tags' => array_combine(Contract::TAGS, Contract::TAGS)] as $kind => $items) {
            $class = $kind === 'lists' ? Lists::class : Tag::class;
            foreach ($items as $key => $title) {
                $entity = $class::find($map[$kind][$key] ?? 0);
                if (! $entity || $entity->slug !== 'jakawi-v1-'.$key || $entity->title !== $title) {
                    throw new BridgeError('SCHEMA_DRIFT', 503);
                }
            }
        }
        $fields = (new CustomContactField)->getGlobalFields()['fields'];
        foreach (Contract::FIELDS as $key) {
            $matches = array_values(array_filter($fields, fn ($f) => $f['slug'] === $key));
            if (count($matches) !== 1 || $matches[0]['type'] !== $this->type($key) || $matches[0]['label'] !== 'JAKAWI '.$key) {
                throw new BridgeError('SCHEMA_DRIFT', 503);
            }
        }

        return $map;
    }
}
