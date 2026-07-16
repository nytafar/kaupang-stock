<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
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

    private const NONCE_ACTION = 'kaupang_stock_order_location';
    private const NONCE_NAME = '_kaupang_stock_location_nonce';
    private static bool $saving = false;

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
        \add_action('woocommerce_process_shop_order_meta', [self::class, 'save'], 20, 2);
        \add_action('save_post_shop_order', [self::class, 'save'], 20, 2);
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
        self::locationSelector($orderId);
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
        self::cogsSection($orderId);
        echo '</div>';
    }

    private static function locationSelector(int $orderId): void {
        if (!Locations::isMulti()) {
            return;
        }
        $order = \wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $selected = (int) $order->get_meta('_kaupang_stock_location_id', true);
        \wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<p class="ks-order-location"><label for="kaupang-stock-order-location"><strong>'
            . \esc_html__('Stock location', 'kaupang-stock') . '</strong></label><br />';
        echo '<select id="kaupang-stock-order-location" name="_kaupang_stock_location_id">';
        echo '<option value="">' . \esc_html__('Automatic routing (until first withdrawal)', 'kaupang-stock') . '</option>';
        foreach (Locations::all(true) as $location) {
            $id = (int) $location['id'];
            echo '<option value="' . $id . '" ' . \selected($selected, $id, false) . '>'
                . \esc_html((string) $location['name']) . '</option>';
        }
        echo '</select><span class="description">'
            . \esc_html__('Changes apply only to future stock withdrawals. Moving an existing withdrawal is handled as a transfer.', 'kaupang-stock')
            . '</span></p>';
    }

    /** @param mixed $postOrOrder */
    public static function save($postId, $postOrOrder = null): void {
        if (self::$saving || !Locations::isMulti()) {
            return;
        }
        $nonce = isset($_POST[self::NONCE_NAME]) ? \sanitize_text_field(\wp_unslash((string) $_POST[self::NONCE_NAME])) : '';
        if ($nonce === '' || !\wp_verify_nonce($nonce, self::NONCE_ACTION)
            || !\current_user_can(Settings::capability())
        ) {
            return;
        }
        $orderId = self::resolveOrderId($postOrOrder);
        if ($orderId <= 0) {
            $orderId = (int) $postId;
        }
        $order = \wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $locationId = isset($_POST['_kaupang_stock_location_id']) ? (int) $_POST['_kaupang_stock_location_id'] : 0;
        if ($locationId > 0 && !Locations::isActive($locationId)) {
            return;
        }
        self::$saving = true;
        try {
            if ($locationId > 0) {
                $order->update_meta_data('_kaupang_stock_location_id', $locationId);
            } else {
                $order->delete_meta_data('_kaupang_stock_location_id');
            }
            $order->save_meta_data();
        } finally {
            self::$saving = false;
        }
    }

    /**
     * Ledger COGS per line + order total — the margin spot-check surface.
     * Read-only from the cost tables; shown whenever costing is enabled
     * (independent of the order-meta stamping flag).
     */
    private static function cogsSection(int $orderId): void {
        if (!\Kaupang\Stock\Costing\Costing::enabled()) {
            return;
        }
        $order = \wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $rows  = [];
        $total = 0;
        foreach ($order->get_items('line_item') as $item) {
            $ore = \Kaupang\Stock\Costing\WcCogsBridge::ledgerCogsOreForItem($orderId, (int) $item->get_id());
            if ($ore === null) {
                continue;
            }
            $rows[] = [
                'name' => $item->get_name(),
                'ore'  => $ore,
            ];
            $total += $ore;
        }
        if ($rows === []) {
            return;
        }

        echo '<h4 class="ks-cogs-heading">' . \esc_html__('Cost of goods (FIFO)', 'kaupang-stock') . '</h4>';
        echo '<table class="ks-panel-moves ks-order-cogs"><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . \esc_html((string) $row['name']) . '</td>';
            echo '<td class="ks-num">' . \esc_html(self::kr((int) $row['ore'])) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="ks-cogs-total"><td><strong>' . \esc_html__('Total', 'kaupang-stock') . '</strong></td>';
        echo '<td class="ks-num"><strong>' . \esc_html(self::kr($total)) . '</strong></td></tr>';
        echo '</tbody></table>';
    }

    private static function kr(int $ore): string {
        return \number_format_i18n($ore / 100, 2) . ' kr';
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
