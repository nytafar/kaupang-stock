<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Ledger\Reasons;

use Kaupang\Stock\Settings;

defined('ABSPATH') || exit;

/**
 * The admin plumbing every "Lager" screen repeats: nonce + capability guard,
 * post-redirect-get, the notice round trip, the small input parsers, CSV
 * streaming and pagination. Static by design — the pages are static too.
 *
 * ONE query-key convention for the redirect → notice hop: `ks_msg` (success /
 * info), `ks_err` (error), `ks_detail` (free text carried with either).
 */
abstract class Screen {

    /**
     * Capability + nonce, and the request method the handler expects. `GET` is
     * for the wp_nonce_url() links (exports, toggles); everything else must be
     * a real POST.
     */
    public static function guard(string $action, string $method = 'POST'): void {
        if (!\current_user_can(Settings::capability())) {
            \wp_die(\esc_html__('You do not have permission to do this.', 'kaupang-stock'), '', ['response' => 403]);
        }
        \check_admin_referer($action);
        if ($method === 'POST' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            \wp_die(\esc_html__('Invalid request method.', 'kaupang-stock'), '', ['response' => 405]);
        }
    }

    /** @param array<string,scalar> $args */
    public static function redirect(string $slug, array $args): never {
        \wp_safe_redirect(\add_query_arg(
            array_merge(['page' => $slug], $args),
            \admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Print the notice for the current ks_msg / ks_err, if any. A map value is
     * either the message text or [text, notice type] when it is not a plain
     * success.
     *
     * @param array<string,string|array{0:string,1:string}> $messages ks_msg code → text
     * @param array<string,string>                          $errors   ks_err code → text
     */
    public static function notices(array $messages, array $errors = []): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $msg = isset($_GET['ks_msg']) ? \sanitize_key((string) $_GET['ks_msg']) : '';
        $err = isset($_GET['ks_err']) ? \sanitize_key((string) $_GET['ks_err']) : '';
        // phpcs:enable
        if (isset($messages[$msg])) {
            $entry = $messages[$msg];
            self::flash(is_array($entry) ? $entry[1] : 'success', is_array($entry) ? $entry[0] : $entry);
        }
        if (isset($errors[$err])) {
            self::flash('error', $errors[$err]);
        }
    }

    /** The free-text detail carried alongside a notice code. */
    public static function detail(): string {
        // phpcs:ignore WordPress.Security.NonceVerification
        return isset($_GET['ks_detail']) ? \sanitize_text_field(\wp_unslash((string) $_GET['ks_detail'])) : '';
    }

    public static function flash(string $type, string $message): void {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            \esc_attr($type),
            \esc_html($message)
        );
    }

    /** "12" / "1,5" → float; blank or non-numeric → null. */
    /**
     * Note for an adjustment from the posted preset + optional text:
     * "Damaged", "Other: fell off the shelf", or plain text when no preset
     * was posted (REST/CLI callers). Empty string = nothing usable.
     */
    public static function adjustNote(): string {
        $text    = \sanitize_text_field(\wp_unslash((string) ($_POST['note'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification
        $preset  = \sanitize_key((string) ($_POST['preset'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        $presets = Reasons::adjustPresets();
        if ($preset === '' || !isset($presets[$preset])) {
            return $text;
        }
        if ($preset === 'other') {
            return $text;
        }
        return $text !== '' ? $presets[$preset] . ': ' . $text : $presets[$preset];
    }

    /**
     * The preset select + optional text field shared by both adjust forms.
     * With JS the text field stays hidden until "Other" is chosen; without
     * JS both render.
     */
    public static function adjustNoteFields(bool $disabled, string $textClass = ''): void {
        $dis = $disabled ? ' disabled' : '';
        ?>
        <select name="preset" class="ks-adjust-preset" aria-label="<?php \esc_attr_e('Reason', 'kaupang-stock'); ?>" required<?php echo $dis; ?>>
            <option value=""><?php \esc_html_e('Reason…', 'kaupang-stock'); ?></option>
            <?php foreach (Reasons::adjustPresets() as $key => $label): ?>
                <option value="<?php echo \esc_attr($key); ?>"><?php echo \esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="note" class="ks-adjust-note <?php echo \esc_attr($textClass); ?>" maxlength="255" placeholder="<?php \esc_attr_e('Note', 'kaupang-stock'); ?>" aria-label="<?php \esc_attr_e('Note', 'kaupang-stock'); ?>"<?php echo $dis; ?> />
        <?php
    }

    /**
     * From/To date pair with a preset picker (today, 7 days, this month…).
     * Native date inputs are the primitive; the preset select carries no name,
     * so it never reaches the query — admin.js copies its range into the two
     * real fields and lets their change event do the rest.
     */
    public static function dateRange(string $from, string $to, string $fromName = 'from', string $toName = 'to'): void {
        $presets = [
            ''           => \__('Period…', 'kaupang-stock'),
            'today'      => \__('Today', 'kaupang-stock'),
            '7d'         => \__('Last 7 days', 'kaupang-stock'),
            '30d'        => \__('Last 30 days', 'kaupang-stock'),
            'month'      => \__('This month', 'kaupang-stock'),
            'last_month' => \__('Last month', 'kaupang-stock'),
            'year'       => \__('This year', 'kaupang-stock'),
            'all'        => \__('All time', 'kaupang-stock'),
        ];
        ?>
        <span class="ks-daterange">
            <select data-ks-daterange aria-label="<?php \esc_attr_e('Period', 'kaupang-stock'); ?>">
                <?php foreach ($presets as $key => $label): ?>
                    <option value="<?php echo \esc_attr($key); ?>"><?php echo \esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="<?php echo \esc_attr($fromName); ?>" value="<?php echo \esc_attr($from); ?>" aria-label="<?php \esc_attr_e('From', 'kaupang-stock'); ?>" />
            <span class="ks-daterange-sep" aria-hidden="true">–</span>
            <input type="date" name="<?php echo \esc_attr($toName); ?>" value="<?php echo \esc_attr($to); ?>" aria-label="<?php \esc_attr_e('To', 'kaupang-stock'); ?>" />
        </span>
        <?php
    }

    public static function parseDelta(string $raw): ?float {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    }

    /** "101" / "88,50" → øre; '' or negative → null (no cost entered). */
    public static function parseUnitCost(string $raw): ?int {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
            return null;
        }
        return (int) round(((float) $raw) * 100);
    }

    /**
     * Stream a CSV download and stop. BOM first so Excel reads UTF-8 (æøå).
     *
     * @param array<int,string>              $header
     * @param iterable<int,array<int,scalar>> $rows
     */
    public static function streamCsv(string $filename, array $header, iterable $rows, string $delim = ';'): never {
        \nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, $delim);
        foreach ($rows as $row) {
            fputcsv($out, $row, $delim);
        }
        fclose($out);
        exit;
    }

    /** The tablenav pager, or '' for a single page. */
    public static function paginate(int $total, int $perPage, int $page): string {
        $pages = (int) max(1, (int) ceil($total / max(1, $perPage)));
        if ($pages <= 1) {
            return '';
        }
        $links = \paginate_links([
            'base'      => \add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $page,
            'total'     => $pages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]);
        if ($links === null) {
            return '';
        }
        return '<div class="tablenav"><div class="tablenav-pages">'
            . sprintf(
                '<span class="displaying-num">%s</span>',
                \esc_html(sprintf(
                    /* translators: %d: total item count */
                    \_n('%d item', '%d items', $total, 'kaupang-stock'),
                    $total
                ))
            )
            . \wp_kses_post($links)
            . '</div></div>';
    }
}
