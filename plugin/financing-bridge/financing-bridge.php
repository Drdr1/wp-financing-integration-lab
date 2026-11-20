<?php
/**
 * Plugin Name: Financing Bridge
 * Description: Connects WordPress orders to a consumer-financing platform's REST API: approval codes, purchase orders, delivery codes, cancellations, signed webhooks and reconciliation.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author: Ahmed Darder
 * License: GPL-2.0-or-later
 * Text Domain: financing-bridge
 *
 * Configuration lives in wp-config.php, never in the database:
 *
 *   define('FINB_API_BASE', 'https://uat.financing.example/api');
 *   define('FINB_API_KEY', '...');
 *   define('FINB_WEBHOOK_SECRET', '...');   // 32+ characters, shared with the platform
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const FINB_VERSION = '0.1.0';

spl_autoload_register(static function (string $class): void {
    $prefix = 'FinancingBridge\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use FinancingBridge\Admin\OrdersPage;
use FinancingBridge\Api\Client;
use FinancingBridge\Api\WpHttpTransport;
use FinancingBridge\Cli\Command;
use FinancingBridge\Http\OrdersController;
use FinancingBridge\Http\WebhookController;
use FinancingBridge\Security\Signature;
use FinancingBridge\Service\FinancingService;
use FinancingBridge\Store\AuditLog;
use FinancingBridge\Store\OrderRepository;
use FinancingBridge\Store\Schema;

function finb_configured(): bool
{
    return defined('FINB_API_BASE') && defined('FINB_API_KEY') && defined('FINB_WEBHOOK_SECRET');
}

/** @return array{service: FinancingService, orders: OrderRepository, log: AuditLog} */
function finb_container(): array
{
    static $c = null;
    if ($c === null) {
        $orders = new OrderRepository();
        $log = new AuditLog();
        $client = new Client(new WpHttpTransport(), (string) FINB_API_BASE, (string) FINB_API_KEY);
        $c = ['service' => new FinancingService($client, $orders, $log), 'orders' => $orders, 'log' => $log];
    }
    return $c;
}

register_activation_hook(__FILE__, static function (): void {
    Schema::install();
    get_role('administrator')?->add_cap('finb_manage');
    if (!wp_next_scheduled('finb_reconcile')) {
        wp_schedule_event(time() + 300, 'hourly', 'finb_reconcile');
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('finb_reconcile');
});

add_action('plugins_loaded', static function (): void {
    if (get_option('finb_db_version') !== Schema::DB_VERSION) {
        Schema::install();
    }
});

add_action('rest_api_init', static function (): void {
    if (!finb_configured()) {
        return;
    }
    $c = finb_container();
    (new WebhookController(new Signature((string) FINB_WEBHOOK_SECRET), $c['service'], $c['log']))->register();
    (new OrdersController($c['service'], $c['orders']))->register();
});

add_action('admin_menu', static function (): void {
    (new OrdersPage(new OrderRepository()))->register();
});

add_action('finb_reconcile', static function (): void {
    if (finb_configured()) {
        finb_container()['service']->reconcile();
    }
});

if (defined('WP_CLI') && WP_CLI && finb_configured()) {
    $c = finb_container();
    WP_CLI::add_command('financing', new Command($c['service'], $c['orders'], $c['log']));
}
