<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin\Purchasing;

defined('ABSPATH') || exit;

use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\SupplierProducts;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Support\ProductSearch;
use Kaupang\Stock\Admin\PurchasingPage;

/**
 * Printable purchase order (?view=print&po=<id>), the document sent to the supplier.
 *
 * The document is in the supplier's language (country NO → nb_NO, anything else →
 * en_US) via switch_to_locale() around the document only; the wp-admin chrome and the
 * action buttons stay in the user's locale. The buyer (the WooCommerce store name and
 * address) is both the invoice and the delivery address. Supplier-facing columns only:
 * our own product name and SKU stay in the internal editor.
 */
final class PrintView {

    public static function render(): void {
        $poId = (int) ($_GET['po'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        $po   = $poId > 0 ? PurchaseOrders::find($poId) : null;
        if ($po === null) {
            echo '<div class="notice notice-error"><p>' . \esc_html__('Purchase order not found.', 'kaupang-stock') . '</p></div>';
            return;
        }
        $supplier = Suppliers::find((int) ($po['supplier_id'] ?? 0));
        $country  = strtoupper((string) ($supplier['country'] ?? Suppliers::DEFAULT_COUNTRY));
        ?>
        <div class="ks-print-actions">
            <a class="button" href="<?php echo \esc_url(PurchasingPage::url(['view' => 'edit', 'po' => $poId])); ?>"><?php \esc_html_e('Back to order', 'kaupang-stock'); ?></a>
            <button type="button" class="button button-primary" onclick="window.print()"><?php \esc_html_e('Print / Save as PDF', 'kaupang-stock'); ?></button>
        </div>
        <?php
        $switched = \switch_to_locale(self::locale($country));
        try {
            self::document($poId, $po, $supplier, $country);
        } finally {
            if ($switched) {
                \restore_previous_locale();
            }
        }
    }

    /** The supplier's language: Norwegian for a Norwegian supplier, English for everyone else. */
    public static function locale(string $country): string {
        return strtoupper($country) === 'NO' ? 'nb_NO' : 'en_US';
    }

    /**
     * @param array<string,mixed>      $po
     * @param array<string,mixed>|null $supplier
     */
    private static function document(int $poId, array $po, ?array $supplier, string $country): void {
        $supplierId = (int) ($po['supplier_id'] ?? 0);
        $lines      = Lines::forPoWithReceived($poId);
        $dateUtc    = (string) (($po['ordered_at'] ?? '') ?: ($po['created_at'] ?? ''));
        $stamp      = $dateUtc !== '' ? strtotime($dateUtc . ' UTC') : false;
        // wp_date() formats month/day names in the switched locale.
        $date       = $stamp !== false ? (string) \wp_date((string) \get_option('date_format'), $stamp) : '—';
        $buyer      = self::buyerAddress($country);
        ?>
        <article class="ks-po-print" lang="<?php echo \esc_attr(str_replace('_', '-', self::locale($country))); ?>">
            <header>
                <h1><?php \esc_html_e('Purchase Order', 'kaupang-stock'); ?></h1>
                <dl>
                    <div><dt><?php \esc_html_e('Order no.', 'kaupang-stock'); ?></dt><dd>#<?php echo $poId; ?></dd></div>
                    <div><dt><?php \esc_html_e('Date', 'kaupang-stock'); ?></dt><dd><?php echo \esc_html($date); ?></dd></div>
                    <?php if (!empty($po['supplier_ref'])): ?>
                        <div><dt><?php \esc_html_e('Supplier reference', 'kaupang-stock'); ?></dt><dd><?php echo \esc_html((string) $po['supplier_ref']); ?></dd></div>
                    <?php endif; ?>
                </dl>
                <div class="ks-po-parties">
                    <section>
                        <h2><?php \esc_html_e('Supplier', 'kaupang-stock'); ?></h2>
                        <p><?php echo \esc_html((string) ($supplier['name'] ?? Suppliers::name($supplierId))); ?></p>
                    </section>
                    <section>
                        <h2><?php \esc_html_e('Invoice address', 'kaupang-stock'); ?></h2>
                        <p><?php echo \wp_kses($buyer, ['br' => []]); ?></p>
                    </section>
                    <section>
                        <h2><?php \esc_html_e('Delivery address', 'kaupang-stock'); ?></h2>
                        <p><?php echo \wp_kses($buyer, ['br' => []]); ?></p>
                    </section>
                </div>
            </header>
            <table>
                <thead><tr>
                    <th><?php \esc_html_e('Supplier product name', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Supplier SKU', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Qty ordered', 'kaupang-stock'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($lines as $line):
                    $productId = (int) $line['product_id'];
                    $identity  = SupplierProducts::find($supplierId, $productId);
                    $name      = trim((string) ($identity['supplier_name'] ?? ''));
                    if ($name === '') { // never a blank line: fall back to our product name
                        $product = \wc_get_product($productId);
                        $name    = $product instanceof \WC_Product ? $product->get_name() : ProductSearch::label($productId);
                    }
                    ?>
                    <tr>
                        <td><?php echo \esc_html($name); ?></td>
                        <td><?php echo \esc_html((string) ($identity['supplier_sku'] ?? '')); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $line['qty_ordered'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </article>
        <?php
    }

    /**
     * The buyer as an address block (escaped by WooCommerce, `<br/>`-separated): the store
     * name plus the WooCommerce store address, in the store country's format. The country
     * line shows only for a supplier abroad.
     */
    private static function buyerAddress(string $supplierCountry): string {
        $countries = \WC()->countries;
        $abroad    = $supplierCountry !== $countries->get_base_country();
        if ($abroad) {
            \add_filter('woocommerce_formatted_address_force_country_display', '__return_true');
        }
        $address = $countries->get_formatted_address([
            'company'   => \wp_specialchars_decode((string) \get_bloginfo('name'), ENT_QUOTES),
            'address_1' => $countries->get_base_address(),
            'address_2' => $countries->get_base_address_2(),
            'city'      => $countries->get_base_city(),
            'state'     => $countries->get_base_state(),
            'postcode'  => $countries->get_base_postcode(),
            'country'   => $countries->get_base_country(),
        ]);
        if ($abroad) {
            \remove_filter('woocommerce_formatted_address_force_country_display', '__return_true');
        }
        return $address;
    }
}
