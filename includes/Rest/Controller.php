<?php
declare(strict_types=1);

namespace Kaupang\Stock\Rest;

use Kaupang\Stock\Counting\Apply;
use Kaupang\Stock\Counting\CountLines;
use Kaupang\Stock\Counting\Counts;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * The plugin's REST controller — namespace kaupang-stock/v1. The route table
 * lives in Plugin::restRoutes() (class-string callbacks), so this class loads
 * only when one of its routes is dispatched, never on a Store API call or a
 * cart/checkout render that merely fires rest_api_init. Every route's permission_callback is the plugin capability
 * (Settings::capability(), the kaupang/stock/can_manage seam); per-user calls MUST
 * carry the wp_rest nonce as X-WP-Nonce (suite gotcha #1 — the admin JS reads it
 * off the localized KaupangStock global).
 *
 * Routes:
 *  - GET  /movements                      the review/audit drill-down feed
 *  - POST /count-lines/{id}               capture: absolute set, atomic increment, or clear
 *  - POST /count-lines/{id}/override      review: accept a flagged value (trail note)
 *  - POST /counts/{id}/clear              every counted line of one location → uncounted
 *  - POST /counts/{id}/apply              the drift-guarded relative-variance apply
 *  - POST /receive                        delegated VERBATIM to the purchasing module
 *
 * Stock is only ever mutated through the Ledger (via Apply / the Receiving module);
 * this controller reads movements and writes count DOCUMENT rows, nothing else.
 */
final class Controller {

    /**
     * Uniform permission gate for every route. The routes are registered on
     * every REST request (no settings read at rest_api_init), so the master
     * switch is enforced here: disabled = no access, as when they were absent.
     */
    public static function can(): bool {
        return Settings::enabled() && \current_user_can(Settings::capability());
    }

    /**
     * GET /products — small admin-form typeahead (the shell's datalist
     * enhancement). SKU-exact first, then prefix/title matches.
     */
    public static function products(\WP_REST_Request $request): \WP_REST_Response {
        $term = \sanitize_text_field((string) $request->get_param('term'));
        $rows = \Kaupang\Stock\Support\ProductSearch::search($term, 20);
        return new \WP_REST_Response(['results' => $rows], 200);
    }

    /**
     * GET /supplier-search — BRREG typeahead for the supplier form (name or exact
     * org-nr). Gated on po_enabled; returns [] when kaupang-brreg is absent (soft
     * dep — Suppliers::search owns that check). Never writes.
     */
    public static function supplierSearch(\WP_REST_Request $request): \WP_REST_Response {
        if (!Settings::get('po_enabled')) {
            return new \WP_REST_Response(['results' => []], 200);
        }
        $q    = \sanitize_text_field((string) $request->get_param('q'));
        $rows = Suppliers::search($q, 8);
        return new \WP_REST_Response(['results' => $rows], 200);
    }

    /* ------------------------------ Movements ----------------------------- */

    /**
     * GET /movements — the review/audit drill-down feed. Filters map straight onto
     * Movements::query(); `since` is created_at >= (UTC), used by the count-review
     * "movements since count started" drill-down. Rows carry a resolved product
     * label so the client renders without a second round trip.
     */
    public static function movements(\WP_REST_Request $req): \WP_REST_Response {
        $filters = [];

        $productId = (int) $req->get_param('product_id');
        if ($productId > 0) {
            $filters['product_id'] = $productId;
        }
        $locationId = (int) $req->get_param('location_id');
        if (Locations::isMulti() && Locations::isActive($locationId)) {
            $filters['location_id'] = $locationId;
        }
        $refType = \sanitize_key((string) $req->get_param('ref_type'));
        if ($refType !== '') {
            $filters['ref_type'] = $refType;
            $refId = (int) $req->get_param('ref_id');
            if ($refId > 0) {
                $filters['ref_id'] = $refId;
            }
        }
        $batch = \sanitize_text_field((string) $req->get_param('batch'));
        if ($batch !== '') {
            $filters['batch'] = $batch;
        }
        $reason = \sanitize_key((string) $req->get_param('reason'));
        if ($reason !== '') {
            $filters['reason'] = $reason;
        }
        $since = Movements::dateBoundary((string) $req->get_param('since'));
        if ($since !== '') {
            $filters['created_from'] = $since;
        }

        $page    = max(1, (int) $req->get_param('page'));
        $perPage = (int) $req->get_param('per_page');
        $perPage = $perPage > 0 ? $perPage : 50;

        // The product-scoped drill-down reads oldest-first (movements since the
        // count started); the audit feed stays newest-first.
        $result = Movements::query($filters, $page, $perPage, $since !== '' && $productId > 0 ? 'ASC' : 'DESC');

        $labels = [];
        foreach ($result['rows'] as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($labels[$pid])) {
                $labels[$pid] = ProductSearch::label($pid);
            }
        }

        return \rest_ensure_response([
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'labels'   => $labels,
        ]);
    }

    /* ------------------------------ Count lines --------------------------- */

    /**
     * POST /count-lines/{id} {counted?, increment?, clear?}. Gated on
     * counting_enabled and a parent count in {open, review}. `increment` is the
     * atomic scanner op; `counted` is an absolute set; `clear: true` sets the line
     * back to uncounted. Returns the updated line row (with recount flag
     * recomputed inside CountLines). 409 when the count is not open for counting
     * any more — including an apply that has already read the lines.
     */
    public static function countLine(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        if (!Settings::get('counting_enabled')) {
            return new \WP_Error('counting_disabled', \__('Counting is not enabled.', 'kaupang-stock'), ['status' => 403]);
        }
        $lineId = (int) $req['id'];

        $params      = $req->get_json_params();
        $params      = is_array($params) ? $params : [];
        $hasCounted  = array_key_exists('counted', $params) || $req->get_param('counted') !== null;
        $hasIncr     = array_key_exists('increment', $params) || $req->get_param('increment') !== null;
        $clear       = (bool) ($params['clear'] ?? $req->get_param('clear'));

        if (!$hasCounted && !$hasIncr && !$clear) {
            return new \WP_Error('missing_value', \__('Provide either counted or increment.', 'kaupang-stock'), ['status' => 400]);
        }

        // The write ops themselves re-check writability (line exists, count open|
        // review) under the count lock and return null when the line is not writable.
        if ($clear) {
            $row = CountLines::clear($lineId);
        } elseif ($hasIncr) {
            $by  = (float) ($params['increment'] ?? $req->get_param('increment'));
            $row = CountLines::increment($lineId, $by);
        } else {
            $counted = (float) ($params['counted'] ?? $req->get_param('counted'));
            $row     = CountLines::setCounted($lineId, $counted);
        }

        if ($row === null) {
            return new \WP_Error(
                'line_not_writable',
                \__('That line cannot be updated (it may not exist, or its count is not open for counting).', 'kaupang-stock'),
                ['status' => 409]
            );
        }

        return \rest_ensure_response(['line' => self::linePayload($row)]);
    }

    /**
     * POST /count-lines/{id}/override {note}. Review status only. Clears the
     * recount flag (accepting the counted value) and appends a trail line to the
     * parent count's note — never edits `counted`. A fresh count pass is the only
     * thing that changes what was counted.
     */
    public static function countLineOverride(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        if (!Settings::get('counting_enabled')) {
            return new \WP_Error('counting_disabled', \__('Counting is not enabled.', 'kaupang-stock'), ['status' => 403]);
        }
        $lineId = (int) $req['id'];
        $note   = \sanitize_text_field((string) ($req->get_json_params()['note'] ?? $req->get_param('note')));
        if ($note === '') {
            return new \WP_Error('note_required', \__('A note is required to override a flagged line.', 'kaupang-stock'), ['status' => 400]);
        }

        $line = Counts::line($lineId);
        if ($line === null) {
            return new \WP_Error('line_not_found', \__('Line not found.', 'kaupang-stock'), ['status' => 404]);
        }
        $count = Counts::find((int) $line['count_id']);
        if ($count === null || (string) $count['status'] !== Counts::STATUS_REVIEW) {
            return new \WP_Error('not_review', \__('Overrides are only possible while the count is in review.', 'kaupang-stock'), ['status' => 409]);
        }

        // Re-checked under the count lock: an apply may have claimed it since.
        $row = CountLines::clearRecountFlag($lineId);
        if ($row === null) {
            return new \WP_Error('not_review', \__('Overrides are only possible while the count is in review.', 'kaupang-stock'), ['status' => 409]);
        }

        $user  = \wp_get_current_user();
        $who   = $user instanceof \WP_User && $user->display_name !== '' ? $user->display_name : ('#' . \get_current_user_id());
        // Multi-location count: the line is one location of the product — name it.
        $label = ProductSearch::label((int) $line['product_id']);
        if (Counts::isMulti((int) $line['count_id'])) {
            $label .= ' @ ' . Locations::name(Counts::lineLocation($line));
        }
        $entry = sprintf(
            /* translators: 1: line id, 2: product label, 3: user display name, 4: note. */
            \__('[override] line #%1$d, %2$s, %3$s: %4$s', 'kaupang-stock'),
            $lineId,
            $label,
            $who,
            $note
        );
        Counts::appendNote((int) $line['count_id'], $entry);

        return \rest_ensure_response(['line' => self::linePayload($row)]);
    }

    /**
     * POST /counts/{id}/clear {location_id}. Every counted line of that location
     * back to uncounted (open or review only): the way out of a location
     * scanned into by mistake, or of counted lines on a location deactivated
     * since the count was created. Returns how many lines were cleared.
     */
    public static function countClearLocation(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        if (!Settings::get('counting_enabled')) {
            return new \WP_Error('counting_disabled', \__('Counting is not enabled.', 'kaupang-stock'), ['status' => 403]);
        }
        $countId    = (int) $req['id'];
        $locationId = (int) $req->get_param('location_id');
        if (Counts::find($countId) === null) {
            return new \WP_Error('count_not_found', \__('Count not found.', 'kaupang-stock'), ['status' => 404]);
        }
        if (!isset(Counts::locations($countId)[$locationId])) {
            return new \WP_Error('location_not_in_count', \__('That location is not part of this count.', 'kaupang-stock'), ['status' => 400]);
        }
        $cleared = CountLines::clearLocation($countId, $locationId);
        if ($cleared === null) {
            return new \WP_Error(
                'line_not_writable',
                \__('That line cannot be updated (it may not exist, or its count is not open for counting).', 'kaupang-stock'),
                ['status' => 409]
            );
        }
        return \rest_ensure_response(['cleared' => $cleared, 'location_id' => $locationId]);
    }

    /**
     * POST /counts/{id}/apply {confirmed}. Passes straight through Apply::apply()
     * (which owns the review-only gate, drift guard, and idempotency).
     */
    public static function countApply(\WP_REST_Request $req): \WP_REST_Response {
        $countId   = (int) $req['id'];
        $confirmed = (bool) ($req->get_json_params()['confirmed'] ?? $req->get_param('confirmed'));
        return \rest_ensure_response(Apply::apply($countId, $confirmed));
    }

    /* ------------------------------ Receive ------------------------------- */

    /**
     * POST /receive — delegated VERBATIM to the purchasing module's
     * Receiving::receive(). Gated on po_enabled. An incoming site-local occurred_at
     * ('Y-m-d H:i:s' or 'Y-m-d\TH:i') is converted to UTC via get_gmt_from_date
     * BEFORE delegating. The purchasing module is built by another agent — guard
     * with class_exists and a 501-style fallback so this controller ships alone.
     */
    public static function receive(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        if (!Settings::get('po_enabled')) {
            return new \WP_Error('po_disabled', \__('Purchasing is not enabled.', 'kaupang-stock'), ['status' => 403]);
        }

        $receiver = '\\Kaupang\\Stock\\Purchasing\\Receiving';
        if (!class_exists($receiver) || !method_exists($receiver, 'receive')) {
            return new \WP_Error(
                'receiving_unavailable',
                \__('The receiving module is not available yet.', 'kaupang-stock'),
                ['status' => 501]
            );
        }

        $params = $req->get_json_params();
        $params = is_array($params) ? $params : [];

        $poId  = (int) ($params['po_id'] ?? $req->get_param('po_id'));
        $token = \sanitize_text_field((string) ($params['token'] ?? $req->get_param('token')));
        $locationId = (int) ($params['location_id'] ?? $req->get_param('location_id'));

        $rawLines = $params['lines'] ?? $req->get_param('lines');
        $lines    = [];
        foreach ((array) $rawLines as $lineId => $qty) {
            $lines[(int) $lineId] = (float) $qty;
        }

        $confirmed = (bool) ($params['confirmed'] ?? $req->get_param('confirmed'));

        // Per-session actual unit costs in øre (optional, costing feature).
        $rawCosts = $params['costs'] ?? $req->get_param('costs');
        $costs    = [];
        foreach ((array) $rawCosts as $lineId => $ore) {
            if ($ore === null || $ore === '') {
                continue;
            }
            $costs[(int) $lineId] = max(0, (int) $ore);
        }

        // Site-local → UTC before delegating (the ledger stores UTC).
        $occurredAtUtc = null;
        $occurredRaw   = trim((string) ($params['occurred_at'] ?? $req->get_param('occurred_at')));
        if ($occurredRaw !== '') {
            $normalized    = str_replace('T', ' ', $occurredRaw);
            $occurredAtUtc = \get_gmt_from_date($normalized, 'Y-m-d H:i:s');
        }

        $result = \call_user_func([$receiver, 'receive'], $poId, $token, $lines, $occurredAtUtc, $confirmed, $costs, $locationId);
        return \rest_ensure_response($result);
    }

    /* ------------------------------ Helpers ------------------------------- */

    /**
     * Shape a count-line row for the client: typed numbers, product label, the
     * line's location (a line is (product, location)), and the differanse (counted − expected) so the review grid needs no client math.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function linePayload(array $row): array {
        $expected = (float) $row['expected'];
        $counted  = $row['counted'] === null ? null : (float) $row['counted'];
        return [
            'id'         => (int) $row['id'],
            'count_id'   => (int) $row['count_id'],
            'product_id' => (int) $row['product_id'],
            'location_id'=> Counts::lineLocation($row),
            'location'   => Locations::name(Counts::lineLocation($row)),
            'label'      => ProductSearch::label((int) $row['product_id']),
            'expected'   => $expected,
            'counted'    => $counted,
            'difference' => $counted === null ? null : $counted - $expected,
            'recount'    => (int) $row['recount'] === 1,
            'counted_at' => $row['counted_at'] !== null
                ? \get_date_from_gmt((string) $row['counted_at'], 'Y-m-d H:i')
                : null,
        ];
    }

}
