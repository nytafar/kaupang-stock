<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Admin\Purchasing\EditView;
use Kaupang\Stock\Admin\Purchasing\InboundView;
use Kaupang\Stock\Admin\Purchasing\ListView;
use Kaupang\Stock\Admin\Purchasing\PrintView;
use Kaupang\Stock\Admin\Purchasing\ReceiveView;
use Kaupang\Stock\Admin\Purchasing\SuppliersView;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\Assets;

/**
 * "Lager → Innkjøp" (§6.4) — purchase orders, receiving, the inbound view and the
 * supplier registry, all under one page routed by ?view=:
 *
 *   list (default) · edit (?po=<id> | new) · receive (?po=<id>&view=receive) ·
 *   inbound · suppliers
 *
 * Mutations go through admin-post with nonce + capability checks (the structured,
 * nested nature of PO lines makes the Settings API a poor fit — the wholesale
 * GroupsPage pattern). The receive flow is the one exception: its grid POSTs to
 * the kaupang-stock/v1 REST `receive` route (owned by the REST controller) so the
 * client-minted token round-trips as batch id + idempotency key. Suppliers get an
 * optional BRREG "Slå opp" lookup via a separate admin-post round-trip when
 * kaupang-brreg is active (soft dep).
 *
 * Each view is its own class under Admin\Purchasing\ (ListView, EditView, PrintView,
 * ReceiveView, InboundView, SuppliersView); this class keeps the router, the hook
 * registration, the notices and the helpers the views share.
 *
 * Owned-write gating: when NOT in active mode the receive UI is disabled with an
 * explanatory notice (receipts write stock). PO drafting/ordering and the supplier
 * registry work in any mode — only posting goods needs active mode.
 */
final class PurchasingPage {

    public static function register(): void {
        \add_action('admin_enqueue_scripts', [self::class, 'assets']);

        \add_action('admin_post_' . EditView::SAVE_PO, [EditView::class, 'handleSavePo']);
        \add_action('admin_post_' . EditView::ORDER_PO, [EditView::class, 'handleOrderPo']);
        \add_action('admin_post_' . EditView::CANCEL_PO, [EditView::class, 'handleCancelPo']);
        \add_action('admin_post_' . EditView::SAVE_LINE, [EditView::class, 'handleSaveLine']);
        \add_action('admin_post_' . EditView::DELETE_LINE, [EditView::class, 'handleDeleteLine']);
        \add_action('admin_post_' . SuppliersView::SAVE_SUPPLIER, [SuppliersView::class, 'handleSaveSupplier']);
        \add_action('admin_post_' . SuppliersView::TOGGLE_SUPP, [SuppliersView::class, 'handleToggleSupplier']);
        \add_action('admin_post_' . SuppliersView::BRREG_LOOKUP, [SuppliersView::class, 'handleBrregLookup']);
    }

    /** Enqueue purchasing assets on this screen only, on top of the shared handles. */
    public static function assets(string $hook): void {
        $page = isset($_GET['page']) ? \sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ($page !== Menu::SLUG_PURCHASE) {
            return;
        }
        \wp_enqueue_style(
            'kaupang-stock-purchasing',
            KAUPANG_STOCK_URL . 'assets/purchasing.css',
            [Menu::STYLE_HANDLE],
            Assets::ver('assets/purchasing.css')
        );
        \wp_enqueue_script(
            'kaupang-stock-purchasing',
            KAUPANG_STOCK_URL . 'assets/purchasing.js',
            [Menu::SCRIPT_HANDLE],
            Assets::ver('assets/purchasing.js'),
            true
        );
        \wp_localize_script('kaupang-stock-purchasing', 'KaupangStockPurchasing', [
            'i18n' => [
                'confirmTitle'   => \__('Confirm over-receipt', 'kaupang-stock'),
                'confirmIntro'   => \__('These lines exceed the remaining quantity. Receiving anyway is allowed (supplier overs are normal):', 'kaupang-stock'),
                'confirmReceive' => \__('Receive anyway', 'kaupang-stock'),
                'cancel'         => \__('Cancel', 'kaupang-stock'),
                'received'       => \__('Goods received.', 'kaupang-stock'),
                'genericError'   => \__('Something went wrong. Please try again.', 'kaupang-stock'),
                'ordered'        => \__('Ordered', 'kaupang-stock'),
                'alreadyGot'     => \__('Received', 'kaupang-stock'),
                'remaining'      => \__('Remaining', 'kaupang-stock'),
                'entered'        => \__('Entered', 'kaupang-stock'),
                'nothingToReceive' => \__('Enter a quantity on at least one line.', 'kaupang-stock'),
                // Reused for the async-picker "Add" buttons the JS renders (already translated).
                'add'            => \__('Add', 'kaupang-stock'),
                'brregNoResults' => \__('No companies found.', 'kaupang-stock'),
            ],
        ]);
    }

    /* --------------------------------- Router -------------------------------- */

    public static function render(): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }
        if (!Settings::enabled() || !Settings::get('po_enabled')) {
            self::renderDisabled();
            return;
        }

        $view = isset($_GET['view']) ? \sanitize_key((string) $_GET['view']) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification

        echo '<div class="wrap ks-wrap ks-purchasing">';
        self::notices();

        switch ($view) {
            case 'edit':
                EditView::render();
                break;
            case 'receive':
                ReceiveView::render();
                break;
            case 'inbound':
                InboundView::render();
                break;
            case 'suppliers':
                SuppliersView::render();
                break;
            case 'print':
                PrintView::render();
                break;
            default:
                ListView::render();
        }

        echo '</div>';
    }

    private static function renderDisabled(): void {
        echo '<div class="wrap ks-wrap"><h1>' . \esc_html__('Purchasing', 'kaupang-stock') . '</h1>';
        echo '<div class="notice notice-info inline"><p>'
            . \esc_html__('Purchasing is not enabled. Turn it on in the stock settings.', 'kaupang-stock')
            . '</p></div></div>';
    }

    /* ------------------------ Helpers shared by the views ------------------------ */

    public static function statusChip(string $status): void {
        printf(
            '<span class="ks-chip ks-chip-%s">%s</span>',
            \esc_attr($status),
            \esc_html(PurchaseOrders::statusLabel($status))
        );
    }

    /** @param array<string,mixed> $args */
    public static function url(array $args): string {
        return \add_query_arg(array_merge(['page' => Menu::SLUG_PURCHASE], $args), \admin_url('admin.php'));
    }

    /** @param array<string,mixed> $args */
    public static function movesUrl(array $args): string {
        return \add_query_arg(array_merge(['page' => Menu::SLUG_MOVES], $args), \admin_url('admin.php'));
    }

    public static function qty(float $q): string {
        // Integer-only v1, but format defensively for the DECIMAL column.
        if (abs($q - round($q)) < 1e-9) {
            return \number_format_i18n($q, 0);
        }
        return \number_format_i18n($q, 3);
    }

    public static function localDate(string $ymd): string {
        $ymd = trim($ymd);
        if ($ymd === '' || $ymd === '0000-00-00') {
            return '—';
        }
        return \esc_html($ymd);
    }

    /** UTC 'Y-m-d H:i:s' → site-local display. */
    public static function localDateTime(string $utc): string {
        if ($utc === '' || $utc === '0000-00-00 00:00:00') {
            return '—';
        }
        return \get_date_from_gmt($utc, \get_option('date_format') . ' ' . \get_option('time_format'));
    }

    private static function notices(): void {
        Screen::notices(
            [
                'created'      => \__('Draft purchase order created.', 'kaupang-stock'),
                'saved'        => \__('Purchase order saved.', 'kaupang-stock'),
                'ordered'      => \__('Order placed. Lines are now locked.', 'kaupang-stock'),
                'cancelled'    => \__('Purchase order cancelled. Any received stock was kept.', 'kaupang-stock'),
                'line_added'   => \__('Line added.', 'kaupang-stock'),
                'line_merged'  => \__('Product already on the order — its quantity was increased.', 'kaupang-stock'),
                'line_saved'   => \__('Line saved.', 'kaupang-stock'),
                'line_deleted' => \__('Line removed.', 'kaupang-stock'),
                'supp_saved'   => \__('Supplier saved.', 'kaupang-stock'),
                'brreg_ok'     => \__('Company found — the name was filled in below.', 'kaupang-stock'),
            ],
            [
                'notfound'   => \__('Purchase order not found.', 'kaupang-stock'),
                'save'       => \__('Could not save. Please try again.', 'kaupang-stock'),
                'order'      => \__('Could not place the order — add at least one line to a draft first.', 'kaupang-stock'),
                'line'       => \__('Could not save the line.', 'kaupang-stock'),
                'noproduct'  => \__('Pick a product first.', 'kaupang-stock'),
                'locked'     => \__('The order is placed — lines are locked. The change was noted on the order instead.', 'kaupang-stock'),
                'suppname'   => \__('A supplier name is required.', 'kaupang-stock'),
                'orgnr'      => \__('That organisation number failed the MOD11 check.', 'kaupang-stock'),
                'brreg'      => \__('No company found for that organisation number (or the registry was unavailable).', 'kaupang-stock'),
            ]
        );
    }
}
