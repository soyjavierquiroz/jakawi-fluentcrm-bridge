<?php

/**
 * Plugin Name: Jakawi FluentCRM Bridge
 * Description: Disabled-by-default signed JAKAWI contact projection bridge.
 * Version: 1.0.1
 * Requires PHP: 8.1
 */

namespace Jakawi\FluentCrmBridge;

if (! defined('ABSPATH')) {
    exit;
}
foreach (['BridgeError', 'Contract', 'SchemaManager', 'Adapter', 'EventStore'] as $class) {
    require_once __DIR__.'/includes/'.$class.'.php';
}
function enabled(): bool
{
    return defined('JAKAWI_FLUENTCRM_BRIDGE_ENABLED') && JAKAWI_FLUENTCRM_BRIDGE_ENABLED === true;
}
register_activation_hook(__FILE__, function () {
    EventStore::install();
    update_option('jakawi_fluentcrm_bridge_version', '1.0.1', false);
});
add_action('rest_api_init', function () {
    register_rest_route('jakawi-fluentcrm/v1', '/events', [
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($request) {
            try {
                if (! enabled()) {
                    throw new BridgeError('BRIDGE_DISABLED', 503);
                }
                if (strtolower(trim(explode(';', $request->get_header('content-type'))[0])) !== 'application/json') {
                    throw new BridgeError('CONTENT_TYPE', 415);
                }
                $raw = $request->get_body();
                $headers = ['timestamp' => $request->get_header('x-jakawi-timestamp'), 'event_id' => $request->get_header('x-jakawi-event-id'), 'signature' => $request->get_header('x-jakawi-signature')];
                Contract::authenticate($raw, $headers, defined('JAKAWI_FLUENTCRM_BRIDGE_SECRET') ? (string) JAKAWI_FLUENTCRM_BRIDGE_SECRET : '', time());
                $v = Contract::validate($raw, $headers['event_id']);
                if (! function_exists('FluentCrmApi')) {
                    throw new BridgeError('FLUENTCRM_UNAVAILABLE', 503);
                }
                $result = (new EventStore)->process($v, hash('sha256', $raw), [(new Adapter), 'upsert']);

                return new \WP_REST_Response(['result_code' => 'OK'] + $result, 200);
            } catch (BridgeError $e) {
                $response = new \WP_REST_Response(['result_code' => $e->resultCode], $e->httpStatus);
                if ($e->httpStatus === 503) {
                    $response->header('Retry-After', '60');
                }

                return $response;
            } catch (\Throwable) {
                return new \WP_REST_Response(['result_code' => 'INTERNAL_ERROR'], 503);
            }
        },
    ]);
});
add_action('admin_menu', function () {
    add_management_page('Jakawi CRM Bridge', 'Jakawi CRM Bridge', 'manage_options', 'jakawi-fluentcrm-bridge', function () {
        if (! current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $data = (new SchemaManager)->status();
        echo '<div class="wrap"><h1>Jakawi CRM Bridge</h1><pre>'.esc_html(wp_json_encode($data, JSON_PRETTY_PRINT)).'</pre>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('jakawi_fluentcrm_provision');
        echo '<input type="hidden" name="action" value="jakawi_fluentcrm_provision"><button class="button button-primary" type="submit">PROVISION / REPAIR CRM SCHEMA</button></form>';
        $t = EventStore::table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t)));
        if ($exists) {
            $totals = $wpdb->get_results("SELECT status,COUNT(*) AS total FROM $t GROUP BY status", ARRAY_A);
            echo '<pre>'.esc_html(wp_json_encode(['totals' => $totals], JSON_PRETTY_PRINT)).'</pre>';
        }
        echo '</div>';
    });
});
// Only an authenticated administrator POST can provision schema.
function provision_schema(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('POST required', '', ['response' => 405]);
        return;
    }
    if (! current_user_can('manage_options')) {
        wp_die('Forbidden', '', ['response' => 403]);
        return;
    }
    check_admin_referer('jakawi_fluentcrm_provision');
    try {
        (new SchemaManager)->ensure();
    } catch (\Throwable) {
        // The schema manager records a fixed status, never provider error details.
    }
    wp_safe_redirect(admin_url('tools.php?page=jakawi-fluentcrm-bridge'));
    exit;
}
add_action('admin_post_jakawi_fluentcrm_provision', __NAMESPACE__.'\\provision_schema');
