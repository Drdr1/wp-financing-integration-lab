<?php
declare(strict_types=1);

namespace FinancingBridge\Admin;

use FinancingBridge\Domain\State;
use FinancingBridge\Store\OrderRepository;

/** Tools -> Financing orders: read-only overview, orders needing review first. */
final class OrdersPage
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function register(): void
    {
        add_management_page(
            __('Financing orders', 'financing-bridge'),
            __('Financing orders', 'financing-bridge'),
            'finb_manage',
            'financing-orders',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $orders = $this->orders->recent(100);
        usort($orders, static fn ($a, $b) => (int) ($b->state === State::NeedsReview || $b->reviewReason !== null)
            <=> (int) ($a->state === State::NeedsReview || $a->reviewReason !== null));
        echo '<div class="wrap"><h1>' . esc_html__('Financing orders', 'financing-bridge') . '</h1>';
        if (!defined('FINB_API_KEY') || !defined('FINB_WEBHOOK_SECRET')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('FINB_API_KEY and FINB_WEBHOOK_SECRET must be defined in wp-config.php.', 'financing-bridge') . '</p></div>';
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Order', 'State', 'Amount', 'PO', 'Delivery attempts', 'Review'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($orders as $o) {
            printf(
                '<tr><td>%s</td><td><code>%s</code></td><td>%s %s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
                esc_html($o->merchantOrderRef),
                esc_html($o->state->value),
                esc_html(number_format($o->amountMinor / 100, 2)),
                esc_html($o->currency),
                esc_html((string) $o->poId),
                $o->deliveryAttempts,
                esc_html((string) $o->reviewReason)
            );
        }
        echo '</tbody></table></div>';
    }
}
