<?php

use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use Jakawi\FluentCrmBridge\Adapter;
use Jakawi\FluentCrmBridge\BridgeError;
use Jakawi\FluentCrmBridge\Contract;
use Jakawi\FluentCrmBridge\EventStore;
use Jakawi\FluentCrmBridge\SchemaManager;

foreach (['BridgeError', 'Contract', 'EventStore'] as $c) {
    require __DIR__.'/../includes/'.$c.'.php';
}
$count = 0;
function check(bool $v): void
{
    global $count;
    $count++;
    if (! $v) {
        throw new RuntimeException('Assertion '.$count);
    }
}
function fails(callable $fn, string $code): void
{
    try {
        $fn();
    } catch (BridgeError $e) {
        check($e->resultCode === $code);

        return;
    } throw new RuntimeException('Expected '.$code);
}
$id = '9fac1662-b42c-4de7-928a-5c19bfaec434';
$v = ['version' => 1, 'event_id' => $id, 'operation' => 'CONTACT_UPSERT', 'occurred_at' => '2026-10-09T18:00:00Z', 'source' => ['type' => 'signup_completed', 'id' => '1'], 'contact' => ['email' => 'USER@example.test'], 'identity' => ['jakawi_user_id' => '1', 'provider_contact_id' => null], 'lists_add' => ['users'], 'lists_remove' => ['leads'], 'tags_add' => ['jakawi-user'], 'tags_remove' => [], 'fields' => ['marketing_opt_in' => true, 'marketing_opt_in_at' => '2026-10-09T18:00:00Z'], 'marketing' => ['action' => 'GRANT']];
$raw = json_encode($v);
$secret = str_repeat('x', 32);
$now = 1791568800;
$h = ['timestamp' => (string) $now, 'event_id' => $id, 'signature' => hash_hmac('sha256', $now."\n".$id."\n".$raw, $secret)];
Contract::authenticate($raw, $h, $secret, $now);
check(Contract::validate($raw, $id)['contact']['email'] === 'user@example.test');
fails(fn () => Contract::authenticate($raw, $h + [], $secret, $now + 301), 'AUTH_FAILED');
fails(fn () => Contract::authenticate($raw, $h, $secret, $now - 301), 'AUTH_FAILED');
$bad = $h;
$bad['signature'] = str_repeat('0', 64);
fails(fn () => Contract::authenticate($raw, $bad, $secret, $now), 'AUTH_FAILED');
$bad = $h;
$bad['event_id'] = 'not-uuid';
fails(fn () => Contract::authenticate($raw, $bad, $secret, $now), 'AUTH_FAILED');
fails(fn () => Contract::authenticate(str_repeat('a', 65537), $h, $secret, $now), 'BODY_TOO_LARGE');
fails(fn () => Contract::validate($raw, 'aac11662-b42c-4de7-928a-5c19bfaec434'), 'INVALID_CONTRACT');
foreach (['lists_add', 'tags_add', 'fields'] as $key) {
    $bad = $v;
    $bad[$key] = $key === 'fields' ? ['unknown' => 'value'] : ['unknown'];
    fails(fn () => Contract::validate(json_encode($bad), $id), $key === 'fields' ? 'UNKNOWN_KEY' : 'UNKNOWN_ENTITY');
}
$bad = $v;
$bad['payload'] = 'arbitrary';
fails(fn () => Contract::validate(json_encode($bad), $id), 'INVALID_ENVELOPE');
$bad = $v;
$bad['event_id'] = 'bad';
fails(fn () => Contract::validate(json_encode($bad), $id), 'INVALID_CONTRACT');
$bad = $v;
$bad['fields']['first_utm_source'] = 'one';
fails(fn () => Contract::validate(json_encode($bad), $id), 'INCOHERENT_ATTRIBUTION');
$hash = hash('sha256', $raw);
check(EventStore::decision(null, $hash) === 'RESERVE');
check(EventStore::decision(['status' => 'SENT', 'body_hash' => $hash], $hash) === 'SENT');
check(EventStore::decision(['status' => 'PROCESSING', 'body_hash' => $hash], $hash) === 'PROCESSING');
check(EventStore::decision(['status' => 'RETRY', 'body_hash' => $hash], $hash) === 'RESERVE');
check(EventStore::decision(['status' => 'SENT', 'body_hash' => $hash], hash('sha256', 'different')) === 'CONFLICT');
foreach ([[null, 'GRANT', 'subscribed'], [null, 'REVOKE', 'transactional'], [null, 'UNCHANGED', 'transactional'], ['subscribed', 'REVOKE', 'unsubscribed'], ['transactional', 'GRANT', 'subscribed'], ['subscribed', 'GRANT', 'subscribed'], ['subscribed', 'UNCHANGED', 'subscribed']] as [$status,$action,$expected]) {
    check(Contract::subscription($status, $action) === $expected);
}
foreach (['unsubscribed', 'bounced', 'complained', 'spammed'] as $status) {
    check(Contract::subscription($status, 'GRANT') === $status);
}
$entry = file_get_contents(__DIR__.'/../jakawi-fluentcrm-bridge.php');
check(strpos($entry, 'Contract::authenticate') < strpos($entry, 'EventStore)->process'));
check(str_contains(file_get_contents(__DIR__.'/../includes/Contract.php'), 'hash_equals'));
echo "PASS: $count bridge pure assertions\n";
require __DIR__.'/fixtures.php';
require __DIR__.'/../includes/SchemaManager.php';
require __DIR__.'/../includes/Adapter.php';
foreach (Contract::LISTS as $key => $title) {
    Lists::create(['slug' => 'jakawi-v1-'.$key, 'title' => $title]);
}
foreach (Contract::TAGS as $key) {
    Tag::create(['slug' => 'jakawi-v1-'.$key, 'title' => $key]);
}
foreach (Contract::FIELDS as $key) {
    CustomContactField::$fields[] = ['slug' => $key, 'label' => 'JAKAWI '.$key, 'type' => $key === 'jakawi_user_id' ? 'number' : (str_ends_with($key, '_at') ? 'date_time' : 'text')];
}
$adapter = new Adapter;
$pid = $adapter->upsert($v);
$contact = FakeApi::$contacts[$pid];
check($contact->status === 'subscribed');
check($contact->fields['jakawi_user_id'] === '1');
$contact->fields['unrelated_field'] = 'preserved';
$next = $v;
$next['identity']['provider_contact_id'] = $pid;
$next['contact']['email'] = 'changed@example.test';
$next['occurred_at'] = '2026-10-09T18:01:00Z';
$next['marketing']['action'] = 'UNCHANGED';
$next['fields'] = [];
check($adapter->upsert($next) === $pid);
check($contact->email === 'changed@example.test');
check($contact->fields['unrelated_field'] === 'preserved');
FakeApi::$contacts[2] = new FakeContact(2, 'occupied@example.test', 'transactional');
$bad = $next;
$bad['contact']['email'] = 'occupied@example.test';
fails(fn () => $adapter->upsert($bad), 'IDENTITY_CONFLICT');
check($contact->email === 'changed@example.test');
$bad = $next;
$bad['identity']['jakawi_user_id'] = '99';
fails(fn () => $adapter->upsert($bad), 'IDENTITY_CONFLICT');
$next['source']['first_attribution'] = ['occurred_at' => '2026-01-01T00:00:00Z', 'id' => 1];
$next['source']['last_attribution'] = ['occurred_at' => '2026-10-08T00:00:00Z', 'id' => 10];
foreach (['first', 'last'] as $prefix) {
    foreach (['acquisition_provider', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'touch_at', 'campaign_key', 'landing_slug'] as $key) {
        $next['fields'][$prefix.'_'.$key] = $key === 'acquisition_provider' ? 'META' : ($key === 'utm_source' ? 'snapshot' : null);
    }
}
$adapter->upsert($next);
check($contact->fields['first_utm_source'] === 'snapshot');
$late = $next;
$late['source']['first_attribution']['id'] = 2;
$late['fields']['first_utm_campaign'] = 'different-touch';
$late['fields']['first_utm_source'] = 'different';
$late['fields']['last_utm_source'] = 'older';
$late['source']['last_attribution']['occurred_at'] = '2026-01-01T00:00:00Z';
$late['occurred_at'] = '2026-10-09T18:02:00Z';
$adapter->upsert($late);
check($contact->fields['first_utm_source'] === 'snapshot');
check(! isset($contact->fields['first_utm_campaign']));
check($contact->fields['last_utm_source'] === 'snapshot');
$new = $next;
$new['source']['last_attribution']['id'] = 11;
$new['fields']['last_utm_source'] = 'tie-newer';
$new['fields']['last_activity_at'] = '2026-10-10T00:00:00Z';
$adapter->upsert($new);
check($contact->fields['last_utm_source'] === 'tie-newer');
$new['fields']['last_activity_at'] = '2026-01-01T00:00:00Z';
$adapter->upsert($new);
check($contact->fields['last_activity_at'] === '2026-10-10 00:00:00');
$contact->status = 'unsubscribed';
$new['occurred_at'] = '2026-10-10T00:00:00Z';
$new['marketing']['action'] = 'GRANT';
$new['fields']['marketing_opt_in_at'] = '2026-10-10T00:00:00Z';
$adapter->upsert($new);
check($contact->status === 'unsubscribed');
echo "PASS: $count total bridge assertions (including adapter fakes)\n";
// Exercise the actual route callback without booting WordPress or allowing storage.
define('ABSPATH', sys_get_temp_dir().'/jakawi-test-wordpress/');
define('JAKAWI_FLUENTCRM_BRIDGE_ENABLED', true);
define('JAKAWI_FLUENTCRM_BRIDGE_PROVISION', true);
define('JAKAWI_FLUENTCRM_BRIDGE_SECRET', $secret);
class WP_REST_Response
{
    public function __construct(public $data, public $status) {}

    public function header(...$args) {}
}
function register_activation_hook($file, $callback) { $GLOBALS['activation_callback'] = $callback; }
function add_action($name, $callback)
{
    $GLOBALS['actions'][$name] = $callback;
    if ($name === 'rest_api_init') {
        $callback();
    }
}
function register_rest_route($namespace, $path, $args)
{
    $GLOBALS['bridge_callback'] = $args['callback'];
}
class FakeRequest
{
    public function __construct(private $body, private $headers) {}

    public function get_body()
    {
        return $this->body;
    }

    public function get_header($key)
    {
        return $this->headers[$key] ?? '';
    }
}
require __DIR__.'/../jakawi-fluentcrm-bridge.php';
$before = count(FakeApi::$contacts);
$r = ($GLOBALS['bridge_callback'])(new FakeRequest($raw, ['content-type' => 'application/json', 'x-jakawi-timestamp' => (string) time(), 'x-jakawi-event-id' => $id, 'x-jakawi-signature' => str_repeat('0', 64)]));
check($r->status === 401);
check(count(FakeApi::$contacts) === $before);
$r = ($GLOBALS['bridge_callback'])(new FakeRequest(str_repeat('a', 65537), ['content-type' => 'application/json']));
check($r->status === 413);
echo "PASS: $count total bridge assertions (security route + adapter)\n";
define('ARRAY_A', 'ARRAY_A');
class FakeWpdb
{
    public string $prefix = 'test_';

    public array $rows = [];

    public bool $busy = false;

    private array $snapshot = [];

    public function get_charset_collate() { return ''; }

    public function suppress_errors($value)
    {
        return false;
    }

    public function get_var($sql)
    {
        if (str_contains($sql, 'GET_LOCK')) {
            if ($this->busy) {
                return 0;
            }$this->busy = true;

            return 1;
        } if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->busy = false;

            return 1;
        }

        return null;
    }

    public function prepare($sql, ...$args)
    {
        return [$sql, $args];
    }

    public function get_row($q, $type)
    {
        return $this->rows[$q[1][0]] ?? null;
    }

    public function query($q)
    {
        if ($q === 'START TRANSACTION') {
            $this->snapshot = $this->rows;

            return 1;
        }if ($q === 'ROLLBACK') {
            $this->rows = $this->snapshot;

            return 1;
        }if ($q === 'COMMIT') {
            return 1;
        }
        [$sql,$args] = $q;
        if (str_contains($sql, 'ON DUPLICATE KEY')) {
            [$id,$hash,$status,$code] = $args;
            $this->rows[$id] = ['event_id' => $id, 'body_hash' => $hash, 'status' => $status, 'result_code' => $code];
        } else {
            [$id,$hash] = $args;
            if (isset($this->rows[$id])) {
                return false;
            }$this->rows[$id] = ['event_id' => $id, 'body_hash' => $hash, 'status' => 'PROCESSING', 'result_code' => 'RESERVED'];
        }

        return 1;
    }

    public function update($t, $values, $where)
    {
        $this->rows[$where['event_id']] = array_merge($this->rows[$where['event_id']], $values);

        return 1;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb;
$store = new EventStore;
$mutations = 0;
$mutate = function ($event) use (&$mutations, $hash) {
    $mutations++;
    check($GLOBALS['wpdb']->rows[$event['event_id']]['status'] === 'PROCESSING');
    check($GLOBALS['wpdb']->rows[$event['event_id']]['body_hash'] === $hash);

    return 123;
};
$result = $store->process($v, $hash, $mutate);
check($result['provider_contact_id'] === 123);
$result = $store->process($v, $hash, $mutate);
check($result['idempotent'] === true);
check($mutations === 1);
fails(fn () => $store->process($v, hash('sha256', 'modified'), $mutate), 'EVENT_BODY_CONFLICT');
check($mutations === 1);
$GLOBALS['wpdb']->busy = true;
fails(fn () => $store->process($v, $hash, $mutate), 'IN_PROGRESS');
check($mutations === 1);
$GLOBALS['wpdb']->busy = false;
$retry = $v;
$retry['event_id'] = 'cf32f14e-c152-4f84-bcaf-b159e84c4932';
fails(fn () => $store->process($retry, $hash, fn () => throw new BridgeError('PROVIDER_ERROR', 503)), 'PROVIDER_ERROR');
check($GLOBALS['wpdb']->rows[$retry['event_id']]['status'] === 'RETRY');
$result = $store->process($retry, $hash, $mutate);
check($result['provider_contact_id'] === 123);
check($GLOBALS['wpdb']->rows[$retry['event_id']]['status'] === 'SENT');
echo "PASS: $count total bridge assertions (transactional store fakes)\n";

$schema = new SchemaManager;
CustomContactField::$fields = [['slug' => 'unrelated', 'label' => 'Unrelated', 'type' => 'text']];
$schema->ensure();
$map = $schema->map();
check(count($map['lists']) === 3);
check(count($map['tags']) === 15);
check(count($map['fields']) === 32);
$schema->ensure();
check(count(Lists::$entities) === 3);
check(count(Tag::$entities) === 15);
check(count(CustomContactField::$fields) === 33);
check($map['fields']['jakawi_user_id'] === 'number');
check($map['fields']['last_activity_at'] === 'date_time');
Lists::$entities[0]->slug = 'incompatible';
fails(fn () => $schema->ensure(), 'SCHEMA_LIST_CONFLICT');
check(count(Lists::$entities) === 3);
check($GLOBALS['fake_options']['jakawi_fluentcrm_schema']['status'] === 'SCHEMA_LIST_CONFLICT');
echo "PASS: $count total bridge assertions (schema idempotency/conflicts)\n";
// Restore valid schema after the deliberate conflict.
Lists::$entities[0]->slug = 'jakawi-v1-leads';
$schema->ensure();
$lead = $v;
$lead['contact']['email'] = 'guest@example.test';
$lead['identity'] = ['jakawi_user_id' => null, 'provider_contact_id' => null];
$lead['fields'] = [];
$lead['marketing']['action'] = 'UNCHANGED';
$lead['lists_add'] = ['leads'];
$lead['lists_remove'] = [];
$lead['tags_add'] = ['jakawi-lead'];
$guestId = $adapter->upsert($lead);
check(FakeApi::$contacts[$guestId]->status === 'transactional');
$registered = $lead;
$registered['identity']['jakawi_user_id'] = '50';
$registered['lists_add'] = ['users'];
$registered['lists_remove'] = ['leads'];
$registered['occurred_at'] = '2026-10-08T18:00:00Z';
check($adapter->upsert($registered) === $guestId);
check(FakeApi::$contacts[$guestId]->fields['jakawi_user_id'] === '50');
check(FakeApi::$contacts[$guestId]->lists === [2]);
echo "PASS: $count total bridge assertions\n";

$guestOnUser = $lead;
$guestOnUser['contact']['email'] = 'guest@example.test';
$guestOnUser['fields'] = ['jakawi_city' => 'Guest City', 'jakawi_lifecycle_stage' => 'lead'];
$guestOnUser['occurred_at'] = '2026-10-11T18:00:00Z';
check($adapter->upsert($guestOnUser) === $guestId);
check(FakeApi::$contacts[$guestId]->fields['jakawi_user_id'] === '50');
check(FakeApi::$contacts[$guestId]->lists === [2]);
check(! isset(FakeApi::$contacts[$guestId]->fields['jakawi_city']));
echo "PASS: $count total bridge assertions\n";

check(count(Contract::FIELDS) === 32);
check(Contract::CRM_SCHEMA_VERSION === 1);
foreach (['fbclid', 'gclid', 'ttclid', 'source-organic'] as $key) {
    $bad = $v;
    $bad['fields'][$key] = 'forbidden';
    fails(fn () => Contract::validate(json_encode($bad), $id), 'UNKNOWN_KEY');
}
$coherent = $v;
foreach (['first', 'last'] as $prefix) {
    $coherent['source'][$prefix.'_attribution'] = ['occurred_at' => $v['occurred_at'], 'id' => 1];
    foreach (['acquisition_provider', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign_key', 'landing_slug', 'touch_at'] as $key) {
        $coherent['fields'][$prefix.'_'.$key] = $key === 'acquisition_provider' ? 'NONE' : ($key === 'touch_at' ? $v['occurred_at'] : null);
    }
}
check(Contract::validate(json_encode($coherent), $id)['fields']['first_utm_term'] === null);
$coherent['fields']['first_touch_at'] = '2026-01-01T00:00:00Z';
fails(fn () => Contract::validate(json_encode($coherent), $id), 'INCOHERENT_ATTRIBUTION');
Lists::$entities[0]->slug = 'deleted-slug';
fails(fn () => $schema->map(), 'SCHEMA_LIST_CONFLICT');
check($schema->status()['status'] === 'CONFLICT');
Lists::$entities[0]->slug = 'jakawi-v1-leads';
$sourceEvent = $next;
$sourceEvent['occurred_at'] = '2026-12-01T00:00:00Z';
$sourceEvent['fields']['last_acquisition_provider'] = 'GOOGLE';
$sourceEvent['tags_add'] = ['source-google'];
$adapter->upsert($sourceEvent);
check(in_array($schema->map()['tags']['source-meta'], $contact->tags, true));
check(! in_array($schema->map()['tags']['source-google'], $contact->tags, true));
$activity = $sourceEvent;
$activity['fields'] = ['last_activity_at' => '2026-12-01T00:00:00Z', 'last_activity_type' => 'benefit_redeemed', 'lead_source' => 'partner_application', 'lead_form' => 'partner_application'];
$adapter->upsert($activity);
$activity['fields'] = ['last_activity_at' => '2026-01-01T00:00:00Z', 'last_activity_type' => 'profile_updated', 'lead_source' => 'registration', 'lead_form' => 'registration'];
$adapter->upsert($activity);
check($contact->fields['last_activity_type'] === 'benefit_redeemed');
check($contact->fields['lead_source'] === 'partner_application');
function current_user_can($cap) { check($cap === 'manage_options'); return $GLOBALS['admin_allowed'] ?? false; }
function wp_die($message, $title, $args) { throw new RuntimeException((string) $args['response']); }
function check_admin_referer($action) { check($action === 'jakawi_fluentcrm_provision'); if (! ($GLOBALS['nonce_valid'] ?? false)) { throw new RuntimeException('nonce rejected'); } }
foreach ([['GET', true, '405'], ['POST', false, '403'], ['POST', true, 'nonce rejected']] as [$method, $allowed, $expected]) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $GLOBALS['admin_allowed'] = $allowed;
    try { ($GLOBALS['actions']['admin_post_jakawi_fluentcrm_provision'])(); throw new RuntimeException('missing guard'); }
    catch (RuntimeException $e) { check($e->getMessage() === $expected); }
}
// Execute activation against a disposable fake WordPress upgrade API.
$upgrade = ABSPATH.'wp-admin/includes';
if (! is_dir($upgrade)) { mkdir($upgrade, 0777, true); }
file_put_contents($upgrade.'/upgrade.php', '<?php function dbDelta($sql) { $GLOBALS["activation_sql"] = $sql; }');
$before = [count(Lists::$entities), count(Tag::$entities), count(CustomContactField::$fields), count(FakeApi::$contacts)];
($GLOBALS['activation_callback'])();
check(str_contains($GLOBALS['activation_sql'], 'jakawi_fluentcrm_bridge_events'));
check($before === [count(Lists::$entities), count(Tag::$entities), count(CustomContactField::$fields), count(FakeApi::$contacts)]);
echo "PASS: $count final bridge assertions\n";

check(Contract::subscription('transactional', 'REVOKE') === 'unsubscribed');
echo "PASS: $count final bridge assertions\n";

function admin_url($path) { return '/wp-admin/'.$path; }
function wp_safe_redirect($url) { check($url === '/wp-admin/tools.php?page=jakawi-fluentcrm-bridge'); throw new RuntimeException('redirected'); }
$GLOBALS['nonce_valid'] = true;
$before = count(FakeApi::$contacts);
try { ($GLOBALS['actions']['admin_post_jakawi_fluentcrm_provision'])(); }
catch (RuntimeException $e) { check($e->getMessage() === 'redirected'); }
check($schema->status()['status'] === 'READY');
check(count(FakeApi::$contacts) === $before);
echo "PASS: $count final bridge assertions\n";

// Reconciliation runs solely against model/option fakes; no WordPress boot.
$beforeContacts = serialize(FakeApi::$contacts);
Lists::$entities = [];
Tag::$entities = [];
CustomContactField::$fields = [];
$GLOBALS['fake_options']['jakawi_fluentcrm_schema'] = [];
check($schema->status()['status'] === 'NOT PROVISIONED');
$schema->ensure();
function readySchema(SchemaManager $schema): void
{
    $status = $schema->status();
    check($status['status'] === 'READY');
    check([$status['lists'], $status['tags'], $status['custom_fields']] === [3, 15, 32]);
    check(count(Lists::$entities) === 3 && count(Tag::$entities) === 15 && count(CustomContactField::$fields) === 32);
}
readySchema($schema);
$original = $schema->map();
$schema->ensure();
readySchema($schema);
check($schema->map() === $original);
foreach (['lists', 'tags', 'fields'] as $kind) {
    $previous = $schema->map();
    if ($kind === 'lists') {
        Lists::$entities = array_slice(Lists::$entities, 1);
    } elseif ($kind === 'tags') {
        Tag::$entities = array_slice(Tag::$entities, 1);
    } else {
        CustomContactField::$fields = array_slice(CustomContactField::$fields, 1);
    }
    check($schema->status()['status'] === 'DRIFT');
    fails(fn () => $schema->map(), 'SCHEMA_DRIFT');
    $schema->ensure();
    readySchema($schema);
    foreach (['lists', 'tags'] as $group) {
        foreach ($previous[$group] as $key => $entityId) {
            if ($group === $kind && $key === ($kind === 'lists' ? 'leads' : Contract::TAGS[0])) {
                check($schema->map()[$group][$key] !== $entityId);
            } else {
                check($schema->map()[$group][$key] === $entityId);
            }
        }
    }
}
// Replacement IDs are reused; titles/labels do not define stable identity.
Lists::$entities[0]->id = 900;
Lists::$entities[0]->title = 'Edited display';
Tag::$entities[0]->id = 901;
Tag::$entities[0]->title = 'Edited display';
CustomContactField::$fields[0]['label'] = 'Edited display';
check($schema->status()['status'] === 'DRIFT');
$schema->ensure();
readySchema($schema);
check(in_array(900, $schema->map()['lists'], true));
check(in_array(901, $schema->map()['tags'], true));
// Partial deletion preserves all remaining compatible IDs.
$keptList = Lists::$entities[0]->id;
$keptTag = Tag::$entities[0]->id;
Lists::$entities = array_slice(Lists::$entities, 0, 1);
Tag::$entities = array_slice(Tag::$entities, 0, 1);
CustomContactField::$fields = array_slice(CustomContactField::$fields, 0, 1);
$schema->ensure();
readySchema($schema);
check(in_array($keptList, $schema->map()['lists'], true));
check(in_array($keptTag, $schema->map()['tags'], true));
// All deleted with an old latched failure: no manual option cleanup required.
Lists::$entities = [];
Tag::$entities = [];
CustomContactField::$fields = [];
$GLOBALS['fake_options']['jakawi_fluentcrm_schema']['status'] = 'SCHEMA_FIELD_CONFLICT';
$GLOBALS['fake_options']['jakawi_fluentcrm_schema']['version'] = 0;
check($schema->status()['status'] === 'DRIFT');
$schema->ensure();
readySchema($schema);
$badFieldKey = CustomContactField::$fields[0]['slug'];
CustomContactField::$fields[0]['type'] = 'textarea';
$beforeEntities = [serialize(Lists::$entities), serialize(Tag::$entities), serialize(CustomContactField::$fields)];
fails(fn () => $schema->ensure(), 'SCHEMA_FIELD_CONFLICT');
$diagnostic = $schema->status()['diagnostic'];
check($schema->status()['status'] === 'CONFLICT');
check($diagnostic === ['entity_kind' => 'FIELD', 'contract_key' => $badFieldKey, 'error_code' => 'SCHEMA_FIELD_CONFLICT', 'reason' => 'INCOMPATIBLE_FIELD_TYPE']);
check($GLOBALS['fake_options']['jakawi_fluentcrm_schema']['diagnostic'] === $diagnostic);
check($beforeEntities === [serialize(Lists::$entities), serialize(Tag::$entities), serialize(CustomContactField::$fields)]);
CustomContactField::$fields[0]['type'] = 'number';
$schema->ensure();
foreach (['lists', 'tags', 'fields'] as $kind) {
    if ($kind === 'fields') {
        CustomContactField::$fields[] = CustomContactField::$fields[0];
        $key = CustomContactField::$fields[0]['slug'];
        $entityKind = 'FIELD';
    } else {
        $class = $kind === 'lists' ? Lists::class : Tag::class;
        $duplicate = clone $class::$entities[0];
        $key = substr($duplicate->slug, strlen('jakawi-v1-'));
        $duplicate->id = 999;
        $class::$entities[] = $duplicate;
        $entityKind = $kind === 'lists' ? 'LIST' : 'TAG';
    }
    fails(fn () => $schema->ensure(), 'SCHEMA_'.$entityKind.'_CONFLICT');
    $diagnostic = $schema->status()['diagnostic'];
    check($diagnostic['entity_kind'] === $entityKind && $diagnostic['contract_key'] === $key && $diagnostic['reason'] === 'AMBIGUOUS_LIVE_MATCHES');
    if ($kind === 'fields') {
        array_pop(CustomContactField::$fields);
    } else {
        array_pop($class::$entities);
    }
    check($schema->status()['status'] === 'DRIFT');
    $schema->ensure();
    readySchema($schema);
}
check(serialize(FakeApi::$contacts) === $beforeContacts);
check(Contract::CRM_SCHEMA_VERSION === 1);
check(str_contains(file_get_contents(__DIR__.'/../jakawi-fluentcrm-bridge.php'), 'PROVISION / REPAIR CRM SCHEMA'));
echo "PASS: $count bridge assertions including schema repair hotfix\n";
