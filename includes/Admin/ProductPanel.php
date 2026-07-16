<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Purchasing\SupplierProducts;
use Kaupang\Stock\Support\ProductSearch;

/**
 * "Lager" meta box on the product edit screen (§6.6): current quantities for the
 * entity that owns _stock, the last 10 movements, and a link to filtered
 * Bevegelser. For a variable parent it lists each stock-managing variation child
 * briefly. Cheap indexed reads only — balances/movements queried directly, never
 * a WC_Product loop.
 */
final class ProductPanel {

    public static function register(): void {
        \add_action('add_meta_boxes', [self::class, 'add'], 30, 2);
    }

    /** @param mixed $post */
    public static function add(string $postType, $post): void {
        if ($postType !== 'product') {
            return;
        }
        \add_meta_box(
            'kaupang-stock-panel',
            \__('Stock (Lager)', 'kaupang-stock'),
            [self::class, 'render'],
            'product',
            'side',
            'default'
        );
    }

    /** @param mixed $post */
    public static function render($post): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }
        $product = \wc_get_product(\is_object($post) ? (int) $post->ID : (int) $post);
        if (!$product instanceof \WC_Product) {
            echo '<p>' . \esc_html__('Save the product first to see stock.', 'kaupang-stock') . '</p>';
            return;
        }

        // Enqueue our styling on the product screen (the shared handle is
        // registered by Menu on admin_enqueue_scripts).
        if (\wp_style_is(Menu::STYLE_HANDLE, 'registered')) {
            \wp_enqueue_style(Menu::STYLE_HANDLE);
        }

        echo '<div class="ks-product-panel">';

        if ($product->is_type('variable')) {
            self::renderVariable($product);
        } else {
            $managedId = $product->get_stock_managed_by_id();
            self::renderSingle($managedId);
        }

        echo '</div>';
    }

    private static function renderSingle(int $managedId): void {
        $row      = Balances::row($managedId);
        $onHand   = $row !== null ? (float) $row['on_hand'] : 0.0;
        $reserved = self::reserved($managedId);
        $avail    = $onHand - $reserved;

        echo '<table class="ks-panel-figures"><tbody>';
        self::figureRow(\__('On hand', 'kaupang-stock'), $onHand);
        self::figureRow(\__('Reserved', 'kaupang-stock'), $reserved);
        self::figureRow(\__('Available', 'kaupang-stock'), $avail);
        echo '</tbody></table>';

        self::supplierLinks([$managedId], false);
        self::recentMovements($managedId);
        self::movementsLink($managedId);
    }

    private static function renderVariable(\WC_Product $product): void {
        $childIds = $product->get_children();
        if (empty($childIds)) {
            echo '<p>' . \esc_html__('No variations.', 'kaupang-stock') . '</p>';
            return;
        }

        // One balances read for all children; only those that own _stock appear.
        $balances = Balances::rows(array_map('intval', $childIds));
        if (empty($balances)) {
            echo '<p>' . \esc_html__('No stock-managed variations.', 'kaupang-stock') . '</p>';
            return;
        }

        echo '<table class="ks-panel-figures ks-panel-variations"><thead><tr>';
        echo '<th>' . \esc_html__('Variation', 'kaupang-stock') . '</th>';
        echo '<th class="ks-num">' . \esc_html__('On hand', 'kaupang-stock') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($balances as $childId => $row) {
            $url = \add_query_arg(['page' => Menu::SLUG_MOVES, 'product_id' => (int) $childId], \admin_url('admin.php'));
            echo '<tr>';
            printf(
                '<td><a href="%s">%s</a></td>',
                \esc_url($url),
                \esc_html(ProductSearch::label((int) $childId))
            );
            echo '<td class="ks-num">' . \esc_html(self::qty((float) $row['on_hand'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        self::supplierLinks(array_map('intval', array_keys($balances)), true);
    }

    /** Read-only supplier identity links for this product (or its variations). */
    private static function supplierLinks(array $productIds, bool $showProduct): void {
        $rows = SupplierProducts::forProducts($productIds);
        if ($rows === []) {
            return;
        }
        echo '<h4 class="ks-panel-heading">' . \esc_html__('Supplier products', 'kaupang-stock') . '</h4>';
        echo '<table class="ks-panel-suppliers"><thead><tr>';
        if ($showProduct) {
            echo '<th>' . \esc_html__('Product', 'kaupang-stock') . '</th>';
        }
        echo '<th>' . \esc_html__('Supplier', 'kaupang-stock') . '</th>';
        echo '<th>' . \esc_html__('Supplier identity', 'kaupang-stock') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = \add_query_arg([
                'page'     => Menu::SLUG_PURCHASE,
                'view'     => 'suppliers',
                'supplier' => (int) $row['supplier_id'],
            ], \admin_url('admin.php'));
            echo '<tr>';
            if ($showProduct) {
                echo '<td>' . \esc_html(ProductSearch::label((int) $row['product_id'])) . '</td>';
            }
            printf('<td><a href="%s">%s</a></td>', \esc_url($url), \esc_html((string) $row['supplier_label']));
            $identity = array_filter([
                (string) ($row['supplier_name'] ?? ''),
                (string) ($row['supplier_sku'] ?? ''),
            ], static fn (string $value): bool => $value !== '');
            echo '<td>' . \esc_html($identity !== [] ? implode(' · ', $identity) : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function recentMovements(int $productId): void {
        $rows = Movements::forProduct($productId, 10);
        if (empty($rows)) {
            echo '<p class="ks-muted">' . \esc_html__('No movements recorded yet.', 'kaupang-stock') . '</p>';
            return;
        }
        echo '<h4 class="ks-panel-heading">' . \esc_html__('Recent movements', 'kaupang-stock') . '</h4>';
        echo '<table class="ks-panel-moves"><tbody>';
        foreach ($rows as $row) {
            $delta = (float) $row['delta'];
            echo '<tr>';
            echo '<td class="ks-muted">' . \esc_html(self::localTime((string) $row['occurred_at'])) . '</td>';
            printf(
                '<td class="ks-num %s">%s</td>',
                $delta >= 0 ? 'ks-delta-pos' : 'ks-delta-neg',
                \esc_html(self::signed($delta))
            );
            echo '<td><span class="ks-reason-chip">' . \esc_html(Reasons::label((string) $row['reason'])) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function movementsLink(int $productId): void {
        $url = \add_query_arg(['page' => Menu::SLUG_MOVES, 'product_id' => $productId], \admin_url('admin.php'));
        printf(
            '<p class="ks-panel-link"><a href="%s">%s</a></p>',
            \esc_url($url),
            \esc_html__('View all movements →', 'kaupang-stock')
        );
    }

    private static function figureRow(string $label, float $value): void {
        printf(
            '<tr><th scope="row">%s</th><td class="ks-num">%s</td></tr>',
            \esc_html($label),
            \esc_html(self::qty($value))
        );
    }

    private static function reserved(int $productId): float {
        $product = \wc_get_product($productId);
        if (!$product instanceof \WC_Product) {
            return 0.0;
        }
        return (float) \wc_get_held_stock_quantity($product);
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
