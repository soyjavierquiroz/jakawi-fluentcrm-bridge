<?php

namespace FluentCrm\App\Models {
    class Lists
    {
        public static array $entities = [];

        public static function find($id)
        {
            foreach (static::$entities as $entity) {
                if ($entity->id === $id) {
                    return $entity;
                }
            }

            return null;
        }

        public static function where($key, $value)
        {
            return new \FakeEntityQuery(static::class, $key, $value);
        }

        public static function create($data)
        {
            $entity = (object) ($data + ['id' => count(static::$entities) + 1]);
            static::$entities[] = $entity;

            return $entity;
        }
    }
    class Tag extends Lists
    {
        public static array $entities = [];
    }
    class CustomContactField
    {
        public static array $fields = [];

        public function getGlobalFields()
        {
            return ['fields' => self::$fields];
        }

        public function saveGlobalFields($fields)
        {
            self::$fields = $fields;

            return $fields;
        }
    }

    class Subscriber
    {
        public static function explodeFullName($record)
        {
            $parts = explode(' ', $record['full_name']);

            return ['first_name' => array_shift($parts), 'last_name' => implode(' ', $parts)];
        }

        public static function whereHas($relation, $callback)
        {
            $q = new \FakeMetaQuery;
            $callback($q);

            return new \FakeContactQuery($q->value);
        }
    }
}

namespace {
    class FakeEntityQuery
    {
        private array $conditions = [];

        public function __construct(private string $class, $key, $value)
        {
            $this->conditions[] = [$key, $value];
        }

        public function orWhere($key, $value)
        {
            $this->conditions[] = [$key, $value];

            return $this;
        }

        public function get()
        {
            return new FakeCollection(array_values(array_filter($this->class::$entities, function ($entity) {
                foreach ($this->conditions as [$key,$value]) {
                    if ($value === $entity->$key) {
                        return true;
                    }
                }

                return false;
            })));
        }
    }
    function update_option($key, $value, $autoload = false)
    {
        $GLOBALS['fake_options'][$key] = $value;
    }

    use Jakawi\FluentCrmBridge\Contract;

    class FakeMetaQuery
    {
        public $value;

        public function where($key, $value)
        {
            if ($key === 'value') {
                $this->value = $value;
            }

            return $this;
        }
    }
    class FakeContactQuery
    {
        public function __construct(private $uid) {}

        public function get()
        {
            return new FakeCollection(array_values(array_filter(FakeApi::$contacts, fn ($c) => (string) ($c->fields['jakawi_user_id'] ?? '') === (string) $this->uid)));
        }
    }
    class FakeCollection implements ArrayAccess
    {
        public function __construct(private array $items) {}

        public function count()
        {
            return count($this->items);
        }

        public function offsetExists($k): bool
        {
            return isset($this->items[$k]);
        }

        public function offsetGet($k): mixed
        {
            return $this->items[$k];
        }

        public function offsetSet($k, $v): void
        {
            $this->items[$k] = $v;
        }

        public function offsetUnset($k): void
        {
            unset($this->items[$k]);
        }
    }
    class FakeContact
    {
        public array $fields = [];

        public array $state = [];

        public array $lists = [];

        public array $tags = [];

        public string $first_name = '';

        public string $last_name = '';

        public function __construct(public int $id, public string $email, public string $status) {}

        public function custom_fields()
        {
            return $this->fields;
        }

        public function getMeta($k, $type)
        {
            return $this->state;
        }

        public function updateMeta($k, $v, $type)
        {
            $this->state = $v;
        }

        public function save() {}

        public function updateStatus($s)
        {
            $this->status = $s;
        }

        public function syncCustomFieldValues($v, $delete)
        {
            foreach ($v as $k => $value) {
                if ($value === '' && $delete) {
                    unset($this->fields[$k]);
                } else {
                    $this->fields[$k] = $value;
                }
            }
        }

        public function hasAnyListId($v)
        {
            return (bool) array_intersect($v, $this->lists);
        }

        public function hasAnyTagId($v)
        {
            return (bool) array_intersect($v, $this->tags);
        }

        public function attachLists($v)
        {
            $this->lists = array_values(array_unique([...$this->lists, ...$v]));
        }

        public function detachLists($v)
        {
            $this->lists = array_values(array_diff($this->lists, $v));
        }

        public function attachTags($v)
        {
            $this->tags = array_values(array_unique([...$this->tags, ...$v]));
        }

        public function detachTags($v)
        {
            $this->tags = array_values(array_diff($this->tags, $v));
        }
    }
    class FakeApi
    {
        public static array $contacts = [];

        public function getContact($v)
        {
            foreach (self::$contacts as $c) {
                if (is_int($v) ? $c->id === $v : $c->email === $v) {
                    return $c;
                }
            }

            return false;
        }

        public function createOrUpdate($v, $force, $delete)
        {
            $c = new FakeContact(count(self::$contacts) + 1, $v['email'], $v['status']);
            self::$contacts[$c->id] = $c;

            return $c;
        }
    }
    function FluentCrmApi($kind)
    {
        return new FakeApi;
    }
    function get_option($key, $default = [])
    {
        if (isset($GLOBALS['fake_options'][$key])) {
            return $GLOBALS['fake_options'][$key];
        }
        if ($key !== 'jakawi_fluentcrm_schema') {
            return $default;
        }

        return ['version' => 1, 'status' => 'READY', 'map' => ['lists' => array_combine(array_keys(Contract::LISTS), [1, 2, 3]), 'tags' => array_combine(Contract::TAGS, range(1, count(Contract::TAGS)))]];
    }
    function do_action(...$args) {}
}
