<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

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
