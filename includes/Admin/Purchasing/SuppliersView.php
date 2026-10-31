<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin\Purchasing;

defined('ABSPATH') || exit;

use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Admin\Menu;
use Kaupang\Stock\Admin\Screen;
use Kaupang\Stock\Admin\PurchasingPage;

/**
 * Supplier registry view (?view=suppliers), with its save/toggle handlers and the
 * optional BRREG lookup round-trip (soft dep on kaupang-brreg).
 */
final class SuppliersView {

    public const SAVE_SUPPLIER = 'kaupang_stock_save_supplier';
    public const TOGGLE_SUPP   = 'kaupang_stock_toggle_supplier';
    public const BRREG_LOOKUP  = 'kaupang_stock_supplier_brreg';

    public static function render(): void {
        $suppliers = Suppliers::all();
        $editId    = isset($_GET['supplier']) ? (int) $_GET['supplier'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $current   = $editId > 0 ? Suppliers::find($editId) : null;

        // A BRREG lookup round-trip may have stashed a name/attribution to prefill.
        $lookup = \get_transient('kaupang_stock_brreg_prefill_' . \get_current_user_id());
        if (is_array($lookup)) {
            \delete_transient('kaupang_stock_brreg_prefill_' . \get_current_user_id());
        } else {
            $lookup = null;
        }
        $prefillName = is_array($lookup) ? (string) ($lookup['name'] ?? '') : '';
        $prefillOrg  = is_array($lookup) ? (string) ($lookup['orgnr'] ?? '') : '';
        ?>
        <h1 class="wp-heading-inline"><?php echo \esc_html(\__('Suppliers', 'kaupang-stock')); ?></h1>
        <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'list'])); ?>" class="page-title-action"><?php \esc_html_e('Back to list', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />

        <div class="ks-tablewrap ks-maxw-60">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php \esc_html_e('Name', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Org. nr', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Country', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Email', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Phone', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Active', 'kaupang-stock'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($suppliers === []): ?>
                    <tr><td colspan="7"><?php \esc_html_e('No suppliers yet.', 'kaupang-stock'); ?></td></tr>
                <?php else: foreach ($suppliers as $s):
                    $sid = (int) $s['id'];
                    ?>
                    <tr>
                        <td><strong><?php echo \esc_html((string) $s['name']); ?></strong></td>
                        <td><?php echo \esc_html((string) ($s['org_nr'] ?? '')); ?></td>
                        <td><?php echo \esc_html(Suppliers::countryName((string) ($s['country'] ?? Suppliers::DEFAULT_COUNTRY))); ?></td>
                        <td><?php echo \esc_html((string) ($s['email'] ?? '')); ?></td>
                        <td><?php echo \esc_html((string) ($s['phone'] ?? '')); ?></td>
                        <td><?php echo !empty($s['active']) ? '✓' : '—'; ?></td>
                        <td>
                            <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'suppliers', 'supplier' => $sid])); ?>"><?php \esc_html_e('Edit', 'kaupang-stock'); ?></a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo \esc_url(self::toggleSupplierUrl($sid, empty($s['active']))); ?>">
                                <?php echo !empty($s['active']) ? \esc_html__('Deactivate', 'kaupang-stock') : \esc_html__('Activate', 'kaupang-stock'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <?php
        // Country drives the org-nr/BRREG affordances (Norway only). A BRREG prefill
        // is by definition a Norwegian entity, so it forces the country to NO.
        $curCountry = $current !== null
            ? (string) ($current['country'] ?? Suppliers::DEFAULT_COUNTRY)
            : Suppliers::DEFAULT_COUNTRY;
        if ($prefillOrg !== '' || $prefillName !== '') {
            $curCountry = 'NO';
        }
        $orgOn = Suppliers::orgNrApplies($curCountry);
        $brreg = Suppliers::brregAvailable();
        ?>
        <h2 class="ks-mt-lg"><?php echo $current ? \esc_html__('Edit supplier', 'kaupang-stock') : \esc_html__('New supplier', 'kaupang-stock'); ?></h2>

        <?php if ($brreg): ?>
            <?php // Separate form (can't nest in the save form); JS fills the fields below on pick, no-JS submits to the lookup round-trip. ?>
            <div class="ks-brreg-lookup" data-ks-brreg-block <?php echo $orgOn ? '' : 'hidden'; ?>>
                <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-brreg-form">
                    <input type="hidden" name="action" value="<?php echo \esc_attr(self::BRREG_LOOKUP); ?>" />
                    <input type="hidden" name="supplier" value="<?php echo (int) $editId; ?>" />
                    <?php \wp_nonce_field(self::BRREG_LOOKUP); ?>
                    <label><?php \esc_html_e('Search Brønnøysund (name or org. nr)', 'kaupang-stock'); ?>
                        <input type="search" name="lookup_query" class="regular-text" autocomplete="off"
                               data-ks-brreg-search value="<?php echo \esc_attr($prefillOrg); ?>"
                               placeholder="<?php \esc_attr_e('Company name or 9-digit org. nr…', 'kaupang-stock'); ?>" />
                    </label>
                    <?php \submit_button(\__('Search', 'kaupang-stock'), 'secondary', 'submit', false); ?>
                    <?php if (Suppliers::brregAvailable()): ?>
                        <span class="description"><?php echo \esc_html(sprintf(/* translators: %s: registry name */ __('Looks the company up in %s and fills the name and org. nr.', 'kaupang-stock'), \Kaupang\Brreg\Client::SOURCE)); ?></span>
                    <?php endif; ?>
                </form>
                <ul class="ks-brreg-results" data-ks-brreg-results hidden aria-label="<?php \esc_attr_e('Search results', 'kaupang-stock'); ?>"></ul>
                <?php if (is_array($lookup) && $prefillName !== ''): ?>
                    <p class="ks-brreg-attrib">
                        <?php echo \esc_html(sprintf(/* translators: %s: registry name */ __('Filled from %s.', 'kaupang-stock'), (string) $lookup['source'])); ?>
                        <a href="<?php echo \esc_url((string) ($lookup['source_url'] ?? '')); ?>" target="_blank" rel="noopener"><?php \esc_html_e('Source', 'kaupang-stock'); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_SUPPLIER); ?>" />
            <input type="hidden" name="supplier" value="<?php echo (int) $editId; ?>" />
            <?php \wp_nonce_field(self::SAVE_SUPPLIER); ?>
            <table class="form-table ks-maxw-52" role="presentation">
                <tr>
                    <th scope="row"><label for="ks-supp-name"><?php \esc_html_e('Name', 'kaupang-stock'); ?></label></th>
                    <td><input type="text" id="ks-supp-name" name="name" class="regular-text" required maxlength="200"
                               value="<?php echo \esc_attr($current !== null ? (string) $current['name'] : $prefillName); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-country"><?php \esc_html_e('Country', 'kaupang-stock'); ?></label></th>
                    <td>
                        <select id="ks-supp-country" name="country" data-ks-supplier-country>
                            <?php foreach (Suppliers::countries() as $code => $label): ?>
                                <option value="<?php echo \esc_attr((string) $code); ?>" <?php \selected($curCountry, (string) $code); ?>>
                                    <?php echo \esc_html((string) $label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr data-ks-orgnr-row <?php echo $orgOn ? '' : 'hidden'; ?>>
                    <th scope="row"><label for="ks-supp-org"><?php \esc_html_e('Org. nr', 'kaupang-stock'); ?></label></th>
                    <td>
                        <input type="text" id="ks-supp-org" name="org_nr" inputmode="numeric" maxlength="11"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['org_nr'] ?? '') : $prefillOrg); ?>" />
                        <?php if ($brreg): ?>
                            <p class="description"><?php \esc_html_e('Norwegian 9-digit organisation number (MOD11-validated).', 'kaupang-stock'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-email"><?php \esc_html_e('Email', 'kaupang-stock'); ?></label></th>
                    <td><input type="email" id="ks-supp-email" name="email" class="regular-text" maxlength="200"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['email'] ?? '') : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-phone"><?php \esc_html_e('Phone', 'kaupang-stock'); ?></label></th>
                    <td><input type="text" id="ks-supp-phone" name="phone" class="regular-text" maxlength="50"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['phone'] ?? '') : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-note"><?php \esc_html_e('Note', 'kaupang-stock'); ?></label></th>
                    <td><input type="text" id="ks-supp-note" name="note" class="large-text" maxlength="255"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['note'] ?? '') : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php \esc_html_e('Active', 'kaupang-stock'); ?></th>
                    <td><label><input type="checkbox" name="active" value="1" <?php \checked($current === null || !empty($current['active'])); ?> />
                        <?php \esc_html_e('Available for new purchase orders', 'kaupang-stock'); ?></label></td>
                </tr>
            </table>
            <?php \submit_button($current ? \__('Save supplier', 'kaupang-stock') : \__('Add supplier', 'kaupang-stock')); ?>
            <?php if ($current): ?>
                <a class="button" href="<?php echo \esc_url(PurchasingPage::url(['view' => 'suppliers'])); ?>"><?php \esc_html_e('Cancel', 'kaupang-stock'); ?></a>
            <?php endif; ?>
        </form>
        <?php
    }

    public static function handleSaveSupplier(): void {
        Screen::guard(self::SAVE_SUPPLIER);
        $sid  = (int) ($_POST['supplier'] ?? 0);
        $data = [
            'name'    => (string) \wp_unslash($_POST['name'] ?? ''),
            'org_nr'  => (string) \wp_unslash($_POST['org_nr'] ?? ''),
            'country' => (string) \wp_unslash($_POST['country'] ?? ''),
            'email'   => (string) \wp_unslash($_POST['email'] ?? ''),
            'phone'   => (string) \wp_unslash($_POST['phone'] ?? ''),
            'note'    => (string) \wp_unslash($_POST['note'] ?? ''),
            'active'  => !empty($_POST['active']),
        ];
        // Surface an invalid org-nr (MOD11) as a soft warning but still save — the
        // supplier registry accepts whatever the operator has (soft-dep posture).
        // Only meaningful for Norwegian suppliers; sanitise() drops org-nr otherwise.
        $org = Suppliers::normalizeOrgNr($data['org_nr']);
        $err = (Suppliers::orgNrApplies($data['country']) && $org['orgnr'] !== '' && !$org['valid']) ? 'orgnr' : '';

        try {
            if ($sid > 0) {
                Suppliers::update($sid, $data);
            } else {
                $sid = Suppliers::create($data);
            }
        } catch (\InvalidArgumentException $e) {
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'suppliers', 'ks_err' => 'suppname']);
        } catch (\Throwable $e) {
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'suppliers', 'ks_err' => 'save']);
        }
        $args = ['view' => 'suppliers', 'ks_msg' => 'supp_saved'];
        if ($err !== '') {
            $args['ks_err'] = $err;
        }
        Screen::redirect(Menu::SLUG_PURCHASE, $args);
    }

    public static function handleToggleSupplier(): void {
        Screen::guard(self::TOGGLE_SUPP, 'GET');
        $sid    = (int) ($_GET['supplier'] ?? 0);
        $active = !empty($_GET['active']);
        if ($sid > 0) {
            Suppliers::setActive($sid, $active);
        }
        Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'suppliers', 'ks_msg' => 'supp_saved']);
    }

    /**
     * BRREG search round-trip — the no-JS fallback for the supplier form's typeahead
     * (the JS path fills the fields inline via the REST supplier-search route). The
     * query is a company name OR an org-nr: a 9-digit number is resolved directly,
     * otherwise the top name hit is taken. The chosen entity's name + org-nr are
     * stashed in a short-lived transient and the form prefills from it. Fails soft
     * (no result) when brreg is absent / nothing matches / BRREG is unavailable.
     */
    public static function handleBrregLookup(): void {
        Screen::guard(self::BRREG_LOOKUP);
        $sid   = (int) ($_POST['supplier'] ?? 0);
        $query = (string) \wp_unslash($_POST['lookup_query'] ?? '');

        $args = ['view' => 'suppliers'];
        if ($sid > 0) {
            $args['supplier'] = $sid;
        }

        // A single unified entry point: Suppliers::search resolves a 9-digit org-nr
        // via lookup, anything else via name search, and returns the best hits.
        $hits = Suppliers::search($query, 8);
        if ($hits === []) {
            $args['ks_err'] = trim($query) === '' ? 'orgnr' : 'brreg';
            Screen::redirect(Menu::SLUG_PURCHASE, $args);
        }

        $hit = $hits[0];
        \set_transient('kaupang_stock_brreg_prefill_' . \get_current_user_id(), [
            'name'       => (string) $hit['name'],
            'orgnr'      => (string) $hit['orgnr'],
            'source'     => \Kaupang\Brreg\Client::SOURCE,
            'source_url' => \Kaupang\Brreg\Client::SOURCE_URL,
        ], 120);
        $args['ks_msg'] = 'brreg_ok';
        Screen::redirect(Menu::SLUG_PURCHASE, $args);
    }

    private static function toggleSupplierUrl(int $sid, bool $active): string {
        return \wp_nonce_url(
            \add_query_arg(['action' => self::TOGGLE_SUPP, 'supplier' => $sid, 'active' => $active ? 1 : 0], \admin_url('admin-post.php')),
            self::TOGGLE_SUPP
        );
    }
}
