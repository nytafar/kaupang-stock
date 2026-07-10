<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * Read-only "Lager" meta box on the order edit screen (§6.6) — provenance one
 * click away (§5). Lists Movements::forRef('order', $orderId): time, product,
 * Δ, reason, balance_after; or "Ingen bevegelser".
 *
 * HPOS + classic: hooks the HPOS shop-order screen id AND the classic shop_order
 * post type, so it works whichever order storage is authoritative.
 */
final class OrderMetaBox {

    public static function register(): void {
        // HPOS: the screen-specific add_meta_boxes_{screen_id} action fires on
        // the orders screen with the WC_Order passed to the box callback.
        if (function_exists('wc_get_page_screen_id')) {
            $screenId = \wc_get_page_screen_id('shop-order');
            if (is_string($screenId) && $screenId !== '') {
                \add_action('add_meta_boxes_' . $screenId, [self::class, 'addHpos']);
            }
        }
        // Classic post-based orders fallback.
        \add_action('add_meta_boxes', [self::class, 'addClassic'], 30, 2);
    }

    public static function addHpos(): void {
        $screenId = \wc_get_page_screen_id('shop-order');
        \add_meta_box(
            'kaupang-stock-order',
            \__('Stock (Lager)', 'kaupang-stock'),
            [self::class, 'render'],
            $screenId,
            'side',
            'default'
        );
    }

    /** @param mixed $post */
    public static function addClassic(string $postType, $post): void {
        if ($postType !== 'shop_order') {
            return;
        }
        \add_meta_box(
            'kaupang-stock-order',
            \__('Stock (Lager)', 'kaupang-stock'),
            [self::class, 'render'],
            'shop_order',
            'side',
            'default'
        );
    }

    /** @param mixed $postOrOrder WP_Post (classic) or WC_Order (HPOS) */
    public static function render($postOrOrder): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }
        $orderId = self::resolveOrderId($postOrOrder);
        if ($orderId <= 0) {
            return;
        }

        if (\wp_style_is(Menu::STYLE_HANDLE, 'registered')) {
            \wp_enqueue_style(Menu::STYLE_HANDLE);
        }

        $rows = Movements::forRef('order', $orderId);
        echo '<div class="ks-order-box">';
        if (empty($rows)) {
            echo '<p class="ks-muted">' . \esc_html__('No stock movements for this order.', 'kaupang-stock') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="ks-panel-moves ks-order-moves"><thead><tr>';
        echo '<th>' . \esc_html__('Time', 'kaupang-stock') . '</th>';
        echo '<th>' . \esc_html__('Product', 'kaupang-stock') . '</th>';
        echo '<th class="ks-num">Δ</th>';
        echo '<th>' . \esc_html__('Reason', 'kaupang-stock') . '</th>';
        echo '<th class="ks-num">' . \esc_html__('Balance', 'kaupang-stock') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $delta = (float) $row['delta'];
            echo '<tr>';
            echo '<td class="ks-muted">' . \esc_html(self::localTime((string) $row['occurred_at'])) . '</td>';
            echo '<td>' . \esc_html(ProductSearch::label((int) $row['product_id'])) . '</td>';
            printf(
                '<td class="ks-num %s">%s</td>',
                $delta >= 0 ? 'ks-delta-pos' : 'ks-delta-neg',
                \esc_html(self::signed($delta))
            );
            echo '<td><span class="ks-reason-chip">' . \esc_html(Reasons::label((string) $row['reason'])) . '</span></td>';
            echo '<td class="ks-num">' . \esc_html(self::qty((float) $row['balance_after'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    /** @param mixed $postOrOrder */
    private static function resolveOrderId($postOrOrder): int {
        if ($postOrOrder instanceof \WC_Order) {
            return (int) $postOrOrder->get_id();
        }
        if (\is_object($postOrOrder) && isset($postOrOrder->ID)) {
            return (int) $postOrOrder->ID;
        }
        if (is_numeric($postOrOrder)) {
            return (int) $postOrOrder;
        }
        return 0;
    }

    private static function qty(float $q): string {
        if (abs($q - round($q)) < 1e-9) {
            return (string) (int) round($q);
        }
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    }

    private static function signed(float $q): string {
        return ($q >= 0 ? '+' : '−') . self::qty(abs($q));
    }

    private static function localTime(string $utc): string {
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return $utc;
        }
        return \wp_date('Y-m-d H:i', $ts) ?: $utc;
    }
}
