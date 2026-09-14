<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Locations;
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
    private const ACT_LOCATION_CREATE = 'kaupang_stock_location_create';
    private const ACT_LOCATION_RENAME = 'kaupang_stock_location_rename';
    private const ACT_LOCATION_DEFAULT = 'kaupang_stock_location_default';
    private const ACT_LOCATION_ACTIVE = 'kaupang_stock_location_active';

    public static function register(): void {
        \add_action('admin_init', [self::class, 'settings']);
        \add_action('admin_post_' . self::ACT_LOCATION_CREATE, [self::class, 'handleLocationCreate']);
        \add_action('admin_post_' . self::ACT_LOCATION_RENAME, [self::class, 'handleLocationRename']);
        \add_action('admin_post_' . self::ACT_LOCATION_DEFAULT, [self::class, 'handleLocationDefault']);
        \add_action('admin_post_' . self::ACT_LOCATION_ACTIVE, [self::class, 'handleLocationActive']);
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

        // Costing enable transition: stamp the anchor (the movement id costing
        // starts after) once, after the option write — same discipline as the
        // seeding sweep below. Write-once: re-enables keep the original anchor.
        if (empty($old['costing_enabled']) && !empty($new['costing_enabled'])) {
            \Kaupang\Stock\Costing\Costing::stampAnchorIfMissing();
        }

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

        // Pure value cleaning only — the enable-time side effects (seeding
        // sweep, costing anchor) live in maybeSeed(), fired by watch() after
        // the option is written.
        $locationMap = [];
        $vias = is_array($in['order_location_via'] ?? null) ? $in['order_location_via'] : [];
        $locationIds = is_array($in['order_location_id'] ?? null) ? $in['order_location_id'] : [];
        foreach ($vias as $index => $rawVia) {
            $via = mb_substr(\sanitize_text_field(\wp_unslash((string) $rawVia)), 0, 100);
            $locationId = isset($locationIds[$index]) ? (int) $locationIds[$index] : 0;
            if ($via !== '' && Locations::isActive($locationId)) {
                $locationMap[$via] = $locationId;
            }
        }

        return [
            'stock_enabled'          => !empty($in['stock_enabled']),
            'mode'                   => $mode === Settings::MODE_ACTIVE ? Settings::MODE_ACTIVE : Settings::MODE_SHADOW,
            'po_enabled'             => !empty($in['po_enabled']),
            'counting_enabled'       => !empty($in['counting_enabled']),
            'variance_threshold_pct' => max(1, min(100, $threshold)),
            'negative_warning'       => !empty($in['negative_warning']),
            'allow_negative_locations' => !empty($in['allow_negative_locations']),
            'order_location_map'     => $locationMap,
            'costing_enabled'        => !empty($in['costing_enabled']),
            'cogs_order_meta_enabled' => !empty($in['cogs_order_meta_enabled']),
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
        $locations = Locations::all();
        ?>
        <div class="wrap ks-wrap">
            <h1><?php \esc_html_e('Stock — settings', 'kaupang-stock'); ?></h1>

            <?php \settings_errors(Settings::OPTION_KEY); ?>
            <?php Screen::notices(
                [
                    'created' => \__('Location added.', 'kaupang-stock'),
                    'renamed' => \__('Location renamed.', 'kaupang-stock'),
                    'default' => \__('Default location changed.', 'kaupang-stock'),
                    'active'  => \__('Location status changed.', 'kaupang-stock'),
                ],
                ['location' => Screen::detail() !== '' ? Screen::detail() : \__('Could not change the location.', 'kaupang-stock')]
            ); ?>

            <form method="post" action="options.php" class="ks-settings-form">
                <?php \settings_fields(self::GROUP); ?>
                <div class="ks-card">
                <h2><?php \esc_html_e('Ledger', 'kaupang-stock'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php \esc_html_e('Stock ledger', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[stock_enabled]" value="1" <?php \checked($enabled); ?> />
                                <?php \esc_html_e('Record every stock change in the ledger', 'kaupang-stock'); ?></label>
                            <p class="description"><?php \esc_html_e('Seeds opening balances on first enable. History is kept when turned off.', 'kaupang-stock'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Mode', 'kaupang-stock'); ?></th>
                        <td>
                            <fieldset>
                                <label class="ks-radio">
                                    <input type="radio" name="<?php echo \esc_attr($opt); ?>[mode]" value="<?php echo \esc_attr(Settings::MODE_SHADOW); ?>" <?php \checked(($s['mode'] ?? Settings::MODE_SHADOW) !== Settings::MODE_ACTIVE); ?> />
                                    <strong><?php \esc_html_e('Shadow', 'kaupang-stock'); ?></strong> <span class="description"><?php \esc_html_e('record only, nothing written to WooCommerce', 'kaupang-stock'); ?></span>
                                </label>
                                <label class="ks-radio">
                                    <input type="radio" name="<?php echo \esc_attr($opt); ?>[mode]" value="<?php echo \esc_attr(Settings::MODE_ACTIVE); ?>" <?php \checked(($s['mode'] ?? '') === Settings::MODE_ACTIVE); ?> />
                                    <strong><?php \esc_html_e('Active', 'kaupang-stock'); ?></strong> <span class="description"><?php \esc_html_e('adjustments, receipts and counts write to WooCommerce stock', 'kaupang-stock'); ?></span>
                                </label>
                            </fieldset>
                            <?php if ($isActive): ?>
                                <p class="description ks-warning"><?php \esc_html_e('Active: stock operations now change WooCommerce stock.', 'kaupang-stock'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Negative stock', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[negative_warning]" value="1" <?php \checked(!empty($s['negative_warning'])); ?> />
                                <?php \esc_html_e('Flag negative on-hand on Stock status', 'kaupang-stock'); ?></label>
                            <?php if (Locations::isMulti()): ?>
                            <br />
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[allow_negative_locations]" value="1" <?php \checked(!empty($s['allow_negative_locations'])); ?> />
                                <?php \esc_html_e('Allow adjustments and transfers to take a location below zero', 'kaupang-stock'); ?></label>
                            <?php else: ?>
                                <input type="hidden" name="<?php echo \esc_attr($opt); ?>[allow_negative_locations]" value="1" />
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="ks-card">
                <h2><?php \esc_html_e('Modules', 'kaupang-stock'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php \esc_html_e('Purchasing', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[po_enabled]" value="1" <?php \checked(!empty($s['po_enabled'])); ?> />
                                <?php \esc_html_e('Suppliers, purchase orders and receiving', 'kaupang-stock'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Stock counts', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[counting_enabled]" value="1" <?php \checked(!empty($s['counting_enabled'])); ?> />
                                <?php \esc_html_e('Stock counts', 'kaupang-stock'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-variance"><?php \esc_html_e('Recount threshold (%)', 'kaupang-stock'); ?></label></th>
                        <td>
                            <input name="<?php echo \esc_attr($opt); ?>[variance_threshold_pct]" id="ks-variance" type="number" step="1" min="1" max="100" class="small-text"
                                   value="<?php echo \esc_attr((string) (int) ($s['variance_threshold_pct'] ?? 20)); ?>" /> %
                            <p class="description"><?php \esc_html_e('Lines off by more than this are flagged for recount.', 'kaupang-stock'); ?></p>
                        </td>
                    </tr>
                </table>
                </div>

                <?php if (Locations::isMulti()): ?>
                <div class="ks-card">
                <h2><?php \esc_html_e('Order routing', 'kaupang-stock'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php \esc_html_e('Map created_via', 'kaupang-stock'); ?></th>
                        <td>
                            <p class="description"><?php \esc_html_e('Orders from these channels draw stock from the chosen location.', 'kaupang-stock'); ?></p>
                            <table class="widefat striped ks-settings-map"><thead><tr>
                                <th><?php \esc_html_e('created_via', 'kaupang-stock'); ?></th>
                                <th><?php \esc_html_e('Location', 'kaupang-stock'); ?></th>
                            </tr></thead><tbody>
                            <?php
                            $mapRows = is_array($s['order_location_map'] ?? null) ? $s['order_location_map'] : [];
                            $mapRows += array_fill_keys(['', ' ', '  '], 0);
                            foreach ($mapRows as $via => $locationId):
                                $via = trim((string) $via);
                            ?>
                                <tr><td><input type="text" name="<?php echo \esc_attr($opt); ?>[order_location_via][]" value="<?php echo \esc_attr($via); ?>" placeholder="zettle" /></td>
                                <td><select name="<?php echo \esc_attr($opt); ?>[order_location_id][]"><option value="">—</option>
                                    <?php foreach (Locations::all(true) as $location): ?>
                                        <option value="<?php echo (int) $location['id']; ?>" <?php \selected((int) $locationId, (int) $location['id']); ?>><?php echo \esc_html((string) $location['name']); ?></option>
                                    <?php endforeach; ?>
                                </select></td></tr>
                            <?php endforeach; ?>
                            </tbody></table>
                        </td>
                    </tr>
                </table>
                </div>
                <?php endif; ?>

                <div class="ks-card">
                <h2><?php \esc_html_e('Costing', 'kaupang-stock'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php \esc_html_e('Cost tracking (FIFO)', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[costing_enabled]" value="1" <?php \checked(!empty($s['costing_enabled'])); ?> />
                                <?php \esc_html_e('Track cost of goods (FIFO)', 'kaupang-stock'); ?></label>
                            <p class="description"><?php \esc_html_e('Enter opening unit costs on Stock value after enabling.', 'kaupang-stock'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php \esc_html_e('Order COGS', 'kaupang-stock'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo \esc_attr($opt); ?>[cogs_order_meta_enabled]" value="1" <?php \checked(!empty($s['cogs_order_meta_enabled'])); ?> />
                                <?php \esc_html_e('Write FIFO cost onto order lines', 'kaupang-stock'); ?></label>
                            <p class="description"><?php \esc_html_e('Feeds WooCommerce’s Cost of goods sold when that feature is on.', 'kaupang-stock'); ?></p>
                        </td>
                    </tr>
                </table>
                </div>
                <div class="ks-save-bar"><?php \submit_button(null, 'primary', 'submit', false); ?></div>
            </form>

            <div class="ks-card">
            <h2><?php \esc_html_e('Stock locations', 'kaupang-stock'); ?></h2>

            <div class="ks-tablewrap"><table class="wp-list-table widefat striped ks-table ks-location-table"><thead><tr>
                <th><?php \esc_html_e('Name', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Default', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Status', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Actions', 'kaupang-stock'); ?></th>
            </tr></thead><tbody>
            <?php foreach ($locations as $location): $locationId = (int) $location['id']; ?>
                <tr><td>
                    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-inline-form">
                        <?php \wp_nonce_field(self::ACT_LOCATION_RENAME); ?>
                        <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_LOCATION_RENAME); ?>" />
                        <input type="hidden" name="location_id" value="<?php echo $locationId; ?>" />
                        <input type="text" name="name" value="<?php echo \esc_attr((string) $location['name']); ?>" maxlength="100" required />
                        <button class="button button-small" type="submit"><?php \esc_html_e('Rename', 'kaupang-stock'); ?></button>
                    </form>
                </td><td><?php echo !empty($location['is_default']) ? \esc_html__('Yes', 'kaupang-stock') : '—'; ?></td>
                <td><?php echo !empty($location['active']) ? \esc_html__('Active', 'kaupang-stock') : \esc_html__('Inactive', 'kaupang-stock'); ?></td>
                <td>
                    <?php if (empty($location['is_default']) && !empty($location['active'])): ?>
                    <?php self::locationActionForm(self::ACT_LOCATION_DEFAULT, $locationId, \__('Set default', 'kaupang-stock')); ?>
                    <?php endif; ?>
                    <?php self::locationActionForm(self::ACT_LOCATION_ACTIVE, $locationId, !empty($location['active']) ? \__('Deactivate', 'kaupang-stock') : \__('Activate', 'kaupang-stock'), ['active' => empty($location['active']) ? '1' : '0']); ?>
                </td></tr>
            <?php endforeach; ?>
            </tbody></table></div>

            <h3 class="ks-card-sub"><?php \esc_html_e('Add location', 'kaupang-stock'); ?></h3>
            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-location-create">
                <?php \wp_nonce_field(self::ACT_LOCATION_CREATE); ?>
                <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_LOCATION_CREATE); ?>" />
                <input type="text" name="name" maxlength="100" required placeholder="<?php \esc_attr_e('Location name', 'kaupang-stock'); ?>" />
                <label><input type="checkbox" name="make_default" value="1" /> <?php \esc_html_e('Make default', 'kaupang-stock'); ?></label>
                <button type="submit" class="button button-secondary"><?php \esc_html_e('Add location', 'kaupang-stock'); ?></button>
            </form>
            </div>
        </div>
        <?php
    }

    public static function handleLocationCreate(): void {
        Screen::guard(self::ACT_LOCATION_CREATE);
        try {
            Locations::create((string) ($_POST['name'] ?? ''), !empty($_POST['make_default']));
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_msg' => 'created']);
        } catch (\Throwable $e) {
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_err' => 'location', 'ks_detail' => mb_substr($e->getMessage(), 0, 180)]);
        }
    }

    public static function handleLocationRename(): void {
        Screen::guard(self::ACT_LOCATION_RENAME);
        try {
            Locations::rename((int) ($_POST['location_id'] ?? 0), (string) ($_POST['name'] ?? ''));
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_msg' => 'renamed']);
        } catch (\Throwable $e) {
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_err' => 'location', 'ks_detail' => mb_substr($e->getMessage(), 0, 180)]);
        }
    }

    public static function handleLocationDefault(): void {
        Screen::guard(self::ACT_LOCATION_DEFAULT);
        try {
            Locations::setDefault((int) ($_POST['location_id'] ?? 0));
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_msg' => 'default']);
        } catch (\Throwable $e) {
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_err' => 'location', 'ks_detail' => mb_substr($e->getMessage(), 0, 180)]);
        }
    }

    public static function handleLocationActive(): void {
        Screen::guard(self::ACT_LOCATION_ACTIVE);
        try {
            Locations::setActive((int) ($_POST['location_id'] ?? 0), !empty($_POST['active']));
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_msg' => 'active']);
        } catch (\Throwable $e) {
            Screen::redirect(Menu::SLUG_SETTINGS, ['ks_err' => 'location', 'ks_detail' => mb_substr($e->getMessage(), 0, 180)]);
        }
    }

    /** @param array<string,string> $extra */
    private static function locationActionForm(string $action, int $locationId, string $label, array $extra = []): void {
        echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" class="ks-inline-form">';
        \wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . \esc_attr($action) . '" />';
        echo '<input type="hidden" name="location_id" value="' . $locationId . '" />';
        foreach ($extra as $key => $value) {
            echo '<input type="hidden" name="' . \esc_attr($key) . '" value="' . \esc_attr($value) . '" />';
        }
        echo '<button type="submit" class="button button-small">' . \esc_html($label) . '</button></form> ';
    }
}
