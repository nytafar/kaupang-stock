<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Observe\Seeder;
use Kaupang\Stock\Settings;

/**
 * "Lager → Innstillinger" — the master switches every module gates on (§6.5).
 * Built on the Settings API, mirroring kaupang-wholesale's SettingsPage.
 *
 * Every flag defaults false/safe (see Settings::defaults()); a fresh activation
 * records nothing and shows only this screen. The ritual is shadow-first: turn
 * stock_enabled on in `shadow` mode, prove reconciliation is clean over real
 * traffic, then flip `mode` to `active` so owned operations may write through.
 *
 * CRITICAL: when stock_enabled transitions false→true, the option-transition
 * watcher (watch(), hooked on add_option_/update_option_ for the key) runs the
 * enable-time seeding sweep (Seeder::sweep()) AFTER the option is persisted, so
 * Lagerstatus and the reconciler are complete from the moment the feature turns
 * on. The sweep must NOT run inside sanitize(): update_option() sanitizes
 * before it writes, so writing the same option from its own sanitize callback
 * re-enters sanitize with the old DB value and recurses without end (the
 * enable-toggle 500 of 2026-07-10).
 */
final class SettingsPage {

    private const GROUP = 'kaupang_stock_settings_group';

    public static function register(): void {
        \add_action('admin_init', [self::class, 'settings']);
    }

    /**
     * Option-transition watcher — registered unconditionally from Plugin::boot()
     * (the enable transition happens while the plugin is otherwise dormant, and
     * programmatic flips via Settings::update() / WP-CLI must seed too).
     */
    public static function watch(): void {
        \add_action('add_option_' . Settings::OPTION_KEY, [self::class, 'optionAdded'], 10, 2);
        \add_action('update_option_' . Settings::OPTION_KEY, [self::class, 'optionUpdated'], 10, 2);
    }

    /** @param mixed $value */
    public static function optionAdded(string $option, $value): void {
        self::maybeSeed([], is_array($value) ? $value : []);
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public static function optionUpdated($old, $new): void {
        self::maybeSeed(is_array($old) ? $old : [], is_array($new) ? $new : []);
    }

    /**
     * false→true enable transition: prime the whole catalog now. Runs after the
     * option row is persisted; Seeder is idempotent so a double fire is harmless.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     */
    private static function maybeSeed(array $old, array $new): void {
        // Drop the static request-cache after any save so later reads in this
        // request (and the sweep below) see the value just written.
        Settings::flushCache();

        if (!empty($old['stock_enabled']) || empty($new['stock_enabled'])) {
            return;
        }

        $result = Seeder::sweep();

        if (\function_exists('add_settings_error')) {
            \add_settings_error(
                Settings::OPTION_KEY,
                'kaupang_stock_seeded',
                sprintf(
                    /* translators: 1: number of products seeded, 2: total stock-managed products */
                    \esc_html__('Stock ledger enabled. Seeded %1$d of %2$d stock-managed products.', 'kaupang-stock'),
                    (int) $result['seeded'],
                    (int) $result['products']
                ),
                'success'
            );
        }
    }

    public static function settings(): void {
        \register_setting(self::GROUP, Settings::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => Settings::defaults(),
        ]);
    }

    /**
     * @param mixed $input
     * @return array<string,mixed>
     */
    public static function sanitize($input): array {
        $in = is_array($input) ? $input : [];

        $mode      = (string) ($in['mode'] ?? Settings::MODE_SHADOW);
        $threshold = (int) ($in['variance_threshold_pct'] ?? 20);

        // Pure value cleaning only — the enable-time seeding side effect lives
        // in maybeSeed(), fired by watch() after the option is written.
        return [
            'stock_enabled'          => !empty($in['stock_enabled']),
            'mode'                   => $mode === Settings::MODE_ACTIVE ? Settings::MODE_ACTIVE : Settings::MODE_SHADOW,
            'po_enabled'             => !empty($in['po_enabled']),
            'counting_enabled'       => !empty($in['counting_enabled']),
            'variance_threshold_pct' => max(1, min(100, $threshold)),
            'negative_warning'       => !empty($in['negative_warning']),
        ];
    }

    public static function render(): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }
        $s        = Settings::all();
        $opt      = Settings::OPTION_KEY;
        $enabled  = !empty($s['stock_enabled']);
        $isActive = $enabled && ($s['mode'] ?? '') === Settings::MODE_ACTIVE;
        ?>
        <div class="wrap ks-wrap">
            <h1><?php \esc_html_e('Stock — settings', 'kaupang-stock'); ?></h1>
            <p class="description ks-maxw-52">
                <?php \esc_html_e('The ledger is the source of truth for stock; every other number is a projection of it. Turn the ledger on in shadow mode first, prove reconciliation is clean over real traffic, then switch to active mode so adjustments, receipts and counts write through to WooCommerce.', 'kaupang-stock'); ?>
            </p>

            <?php \settings_errors(Settings::OPTION_KEY); ?>

            <form method="post" action="options.php">
                <?php \settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php \esc_html_e('Stock ledger', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[stock_enabled]" value="1" <?php \checked($enabled); ?> />
                                <?php \esc_html_e('Enable ledger-backed stock management', 'kaupang-stock'); ?></label>
                            <p class="description"><?php \esc_html_e('Turning this on seeds an opening balance for every stock-managed product and starts recording every WooCommerce stock change. Turning it off stops the observer; recorded history is kept.', 'kaupang-stock'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Mode', 'kaupang-stock'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block;margin-bottom:.35em">
                                    <input type="radio" name="<?php echo \esc_attr($opt); ?>[mode]" value="<?php echo \esc_attr(Settings::MODE_SHADOW); ?>" <?php \checked(($s['mode'] ?? Settings::MODE_SHADOW) !== Settings::MODE_ACTIVE); ?> />
                                    <strong><?php \esc_html_e('Shadow', 'kaupang-stock'); ?></strong> — <?php \esc_html_e('observe and record only; owned operations do not write to WooCommerce. Run this first.', 'kaupang-stock'); ?>
                                </label>
                                <label style="display:block">
                                    <input type="radio" name="<?php echo \esc_attr($opt); ?>[mode]" value="<?php echo \esc_attr(Settings::MODE_ACTIVE); ?>" <?php \checked(($s['mode'] ?? '') === Settings::MODE_ACTIVE); ?> />
                                    <strong><?php \esc_html_e('Active', 'kaupang-stock'); ?></strong> — <?php \esc_html_e('adjustments, receipts and counts write through to WooCommerce stock.', 'kaupang-stock'); ?>
                                </label>
                            </fieldset>
                            <?php if ($isActive): ?>
                                <p class="description ks-warning"><?php \esc_html_e('Active mode is on: owned stock operations now change WooCommerce _stock. Only switch to active once a shadow run has reconciled cleanly.', 'kaupang-stock'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Purchasing', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[po_enabled]" value="1" <?php \checked(!empty($s['po_enabled'])); ?> />
                                <?php \esc_html_e('Enable suppliers, purchase orders and receiving (Innkjøp)', 'kaupang-stock'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Stock counts', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[counting_enabled]" value="1" <?php \checked(!empty($s['counting_enabled'])); ?> />
                                <?php \esc_html_e('Enable stock counting (Varetelling)', 'kaupang-stock'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-variance"><?php \esc_html_e('Recount threshold (%)', 'kaupang-stock'); ?></label></th>
                        <td>
                            <input name="<?php echo \esc_attr($opt); ?>[variance_threshold_pct]" id="ks-variance" type="number" step="1" min="1" max="100" class="small-text"
                                   value="<?php echo \esc_attr((string) (int) ($s['variance_threshold_pct'] ?? 20)); ?>" /> %
                            <p class="description"><?php \esc_html_e('Count lines whose variance exceeds this percentage are flagged for recount before the count can be applied.', 'kaupang-stock'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Negative stock', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[negative_warning]" value="1" <?php \checked(!empty($s['negative_warning'])); ?> />
                                <?php \esc_html_e('Show a warning chip on Lagerstatus when on-hand is negative', 'kaupang-stock'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php \submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
