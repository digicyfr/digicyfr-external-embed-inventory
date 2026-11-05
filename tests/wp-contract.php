<?php
/** Meaningful real-WordPress contract fixture for the installed exact ZIP. */
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = '127.0.0.1:' . getenv('DIGICYFR_TEST_PORT');
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = getenv('DIGICYFR_TEST_PORT');
$_SERVER['REQUEST_URI'] = '/';
define('WP_ADMIN', true);
require '/var/www/html/wp-load.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_plugin_active('digicyfr-external-embed-inventory/digicyfr-external-embed-inventory.php')) {
	throw new RuntimeException('Exact ZIP plugin is not active');
}
wp_set_current_user(1);
global $wpdb;
$t = DEEI_Storage::tables();
$wpdb->query("DELETE FROM {$t['rows']}");
$wpdb->query("DELETE FROM {$t['coverage']}");
$wpdb->query("DELETE FROM {$t['runs']}");
update_option('deei_current_run', array(), false);
update_option('deei_active_complete_run_id', '', false);
$checks = array();
$assert = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $name);
    }
    $checks[] = $name;
};
$translated_label = __('External Embed Inventory', 'digicyfr-external-embed-inventory');
$assert('pl_PL' === get_locale() && 'Wykaz zewnętrznych osadzeń' === $translated_label, 'bundled Polish translation loads on normal admin bootstrap (locale=' . get_locale() . ', actual=' . $translated_label . ')');

$url_cases = array(
    'https://EXAMPLE.com./token/secret123?token=x#y' => array('external', 'example.com', 'https'),
    'http://example.com:80/a' => array('external', 'example.com', 'http'),
    'https://example.com:8443/a' => array('external', 'example.com:8443', 'https'),
    '//cdn.example.com/a.js' => array('external', 'cdn.example.com', 'unknown'),
    '/local.js' => array('unresolved_relative', '', ''),
    'https://user:pass@bad.example/x' => array('invalid', '', ''),
    'http://[::1]:0/x' => array('invalid', '', ''),
    'https://999.999.999.999/x' => array('invalid', '', ''),
    '#fragment' => array('ignored', '', ''),
    'data:image/gif;base64,AAAA' => array('ignored', '', ''),
    'https://%65xample.com/a' => array('unclassified_host', '', ''),
);
foreach ($url_cases as $raw => $expected) {
    $actual = DEEI_URL::classify($raw);
    $assert(array_values($actual) === $expected, 'URL ' . $raw);
}
$scan = DEEI_Scanner::scan('<!-- wp:group --><div><!-- wp:embed {"url":"https://www.youtube.com/watch?v=x"} --><figure></figure><!-- /wp:embed --></div><!-- /wp:group --><iframe src="https://www.youtube.com/embed/x"></iframe>');
$assert(is_array($scan) && 2 === $scan['candidate_count'] && 2 === count($scan['rows']), 'nested provider hint and iframe each once');
$assert('provider_url_hint' === $scan['rows'][0]['evidence'] && 'saved_resource_url' === $scan['rows'][1]['evidence'], 'evidence distinctions');
$assert(is_wp_error(DEEI_Scanner::scan('<iframe src="https://a.example/x"><script src="https://b.example')), 'incomplete HTML token fails closed');
$assert(is_wp_error(DEEI_Scanner::scan(str_repeat('x', DEEI_Scanner::MAX_BYTES + 1))), 'content byte cap');

foreach (get_posts(array('post_type' => array('post', 'page'), 'post_status' => 'publish', 'posts_per_page' => -1)) as $old) {
    wp_delete_post($old->ID, true);
}
$ids = array();
for ($i = 0; $i < 11; ++$i) {
    $content = 0 === $i
        ? '<iframe src="https://secret.example/token/secret123/a?token=secret"></iframe><img src="https://secret.example/b">'
        : (1 === $i ? '<a href="https://link-only.example/x">only link</a><img srcset="https://outside.example/x 1x">' : '<img src="https://batch.example/' . $i . '">');
    $ids[] = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 0 === $i ? '=SUM(1,2) hostile' : 'Batch ' . $i, 'post_content' => $content), true);
}
$assert(count(array_filter($ids, static fn($v) => is_int($v) && $v > 0)) === 11, '11 posts seeded');
$draft = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'excluded', 'post_content' => '<img src="https://draft.example/x">'), true);
$started = DEEI_Run::start();
$assert(is_array($started) && 'in_progress' === $started['status'], 'start pass');
$first = DEEI_Run::proceed();
$assert(is_array($first) && 'in_progress' === $first['status'] && 10 === (int) $first['scanned_count'], 'first batch is bounded at ten');
$assert('' === get_option('deei_active_complete_run_id'), 'no exportable report after first batch');
$second = DEEI_Run::proceed();
$assert(is_array($second) && 'complete' === $second['status'] && 11 === (int) $second['scanned_count'], 'second batch completes eleven');
$active = (string) get_option('deei_active_complete_run_id');
$assert($active === $second['run_id'], 'complete pointer promoted');
$coverage = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['coverage']} WHERE run_id=%s", $active));
$assert(11 === $coverage, 'coverage includes zero-match post');
$zero_rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['rows']} WHERE run_id=%s AND post_id=%d", $active, $ids[1]));
$assert(0 === $zero_rows, 'link/srcset-only post has zero rows');
$secret_rows = $wpdb->get_results($wpdb->prepare("SELECT host_key,scheme,occurrence_count FROM {$t['rows']} WHERE run_id=%s AND post_id=%d", $active, $ids[0]), ARRAY_A);
$assert(2 === count($secret_rows) && 'secret.example' === $secret_rows[0]['host_key'] && 2 === array_sum(array_map(static fn($row) => (int) $row['occurrence_count'], $secret_rows)), 'host counts preserve source-kind distinctions without URL paths');
$dump = wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['rows']} WHERE run_id=%s", $active), ARRAY_A));
$assert(!str_contains($dump, 'secret123') && !str_contains($dump, '/token/'), 'secret path absent from stored rows');
$stream = tmpfile();
$assert(is_resource($stream) && DEEI_Admin::stream_csv($stream, $second), 'CSV spool');
rewind($stream);
$csv = stream_get_contents($stream);
fclose($stream);
$assert(str_contains($csv, "'=SUM(1,2) hostile") && !str_contains($csv, 'secret123'), 'CSV formula guard and no secret path');

$zero_post = get_post($ids[1]);
$wpdb->update($wpdb->posts, array('post_content' => $zero_post->post_content . '<iframe src="https://late.example/x"></iframe>', 'post_modified_gmt' => $zero_post->post_modified_gmt), array('ID' => $ids[1]));
clean_post_cache($ids[1]);
$coverage_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['coverage']} WHERE run_id=%s AND post_id=%d", $active, $ids[1]), ARRAY_A);
$method = new ReflectionMethod(DEEI_Admin::class, 'coverage_stale');
$assert(true === $method->invoke(null, $coverage_row), 'same-second zero-match content edit becomes stale');

$oversize = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'oversize', 'post_content' => str_repeat('x', DEEI_Scanner::MAX_BYTES + 1)), true);
$restart = DEEI_Run::start();
$assert(is_array($restart) && $active === get_option('deei_active_complete_run_id'), 'new attempt retains active report');
$b1 = DEEI_Run::proceed();
$b2 = DEEI_Run::proceed();
$assert(is_array($b1) && is_array($b2) && 'incomplete' === $b2['status'] && 'post_too_large' === $b2['error'], 'oversize incomplete with named reason across batches');
$assert($active === get_option('deei_active_complete_run_id'), 'incomplete attempt preserves active report');

$wpdb->suppress_errors(true);
wp_delete_post($oversize, true);
$invalid_sql = 'SELECT forced_failure FROM deei_missing_table';
$batch_fault = static fn(string $sql): string => str_contains($sql, 'OCTET_LENGTH(post_content)') ? $invalid_sql : $sql;
$new = DEEI_Run::start();
$assert(is_array($new), 'new pass before batch fault');
add_filter('query', $batch_fault);
$failed = DEEI_Run::proceed();
remove_filter('query', $batch_fault);
$state = DEEI_Run::current();
$assert(is_wp_error($failed) && 'storage_batch_query_failed' === $failed->get_error_code() && 0 === (int) $state['cursor_id'] && $active === get_option('deei_active_complete_run_id'), 'failed batch query retains cursor and prior report');
$retry = DEEI_Run::proceed();
$assert(is_array($retry) && 'in_progress' === $retry['status'] && 10 === (int) $retry['scanned_count'], 'batch SQL retry advances exactly ten');
$prior_cursor = (int) $retry['cursor_id'];

$remaining_hits = 0;
$remaining_fault = static function (string $sql) use ($invalid_sql, &$remaining_hits): string {
    if (str_ends_with(trim($sql), 'ORDER BY ID ASC LIMIT 1') && str_contains($sql, 'post_status =')) {
        ++$remaining_hits;
        return $invalid_sql;
    }
    return $sql;
};
add_filter('query', $remaining_fault);
$failed = DEEI_Run::proceed();
remove_filter('query', $remaining_fault);
$state = DEEI_Run::current();
$assert(is_wp_error($failed) && 'storage_remaining_query_failed' === $failed->get_error_code() && $prior_cursor === (int) $state['cursor_id'], 'remaining-post query failure rolls terminal batch back');
$retry = DEEI_Run::proceed();
$assert(is_array($retry) && 'complete' === $retry['status'] && 11 === (int) $retry['scanned_count'], 'remaining-query retry completes once');
$active = (string) get_option('deei_active_complete_run_id');

$write_fault = static fn(string $sql): string => str_contains($sql, 'deei_rows') && str_starts_with(ltrim($sql), 'INSERT INTO') ? $invalid_sql : $sql;
$new = DEEI_Run::start();
$assert(is_array($new), 'new pass before write fault');
add_filter('query', $write_fault);
$failed = DEEI_Run::proceed();
remove_filter('query', $write_fault);
$state = DEEI_Run::current();
$rolled_rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['rows']} WHERE run_id=%s", $state['run_id']));
$assert(is_wp_error($failed) && 'storage_write_failed' === $failed->get_error_code() && 0 === (int) $state['cursor_id'] && 0 === $rolled_rows && $active === get_option('deei_active_complete_run_id'), 'write fault rolls back rows/cursor and retains report');
$retry = DEEI_Run::proceed();
$retry_final = DEEI_Run::proceed();
$assert(is_array($retry) && is_array($retry_final) && 'complete' === $retry_final['status'] && 11 === (int) $retry_final['scanned_count'], 'write retry has exact count without duplicates');
$active = (string) get_option('deei_active_complete_run_id');

$coverage_fault = static fn(string $sql): string => str_contains($sql, 'SELECT COUNT(*) FROM') && str_contains($sql, 'deei_coverage') ? $invalid_sql : $sql;
$new = DEEI_Run::start();
$assert(is_array($new), 'new pass before terminal coverage fault');
$first = DEEI_Run::proceed();
add_filter('query', $coverage_fault);
$failed = DEEI_Run::proceed();
remove_filter('query', $coverage_fault);
$state = DEEI_Run::current();
$assert(is_wp_error($failed) && 'storage_coverage_query_failed' === $failed->get_error_code() && 10 === (int) $state['scanned_count'] && $active === get_option('deei_active_complete_run_id'), 'failed terminal coverage query cannot promote');
$retry = DEEI_Run::proceed();
$assert(is_array($retry) && 'complete' === $retry['status'], 'coverage query retry completes');
$active = (string) get_option('deei_active_complete_run_id');

update_option('deei_active_complete_run_id', 'stale-pointer', false);
$repaired = DEEI_Run::reconcile();
$assert(is_array($repaired) && $active === get_option('deei_active_complete_run_id'), 'post-commit active pointer is reconciled from ledger');

$collision_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'digest collision', 'post_content' => '<img src="https://one.example/a"><img src="https://two.example/b">'), true);
$collision_filter = static fn(string $digest, string $host): string => str_repeat('a', 64);
$new = DEEI_Run::start();
add_filter('deei_host_digest', $collision_filter, 10, 2);
$first_collision = DEEI_Run::proceed();
$failed = DEEI_Run::proceed();
remove_filter('deei_host_digest', $collision_filter, 10);
$state = DEEI_Run::current();
$collision_rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['rows']} WHERE run_id=%s AND post_id=%d", $state['run_id'], $collision_id));
$assert(is_array($first_collision) && is_wp_error($failed) && 'host_digest_collision' === $failed->get_error_code() && 10 === (int) $state['scanned_count'] && 0 === $collision_rows && $active === get_option('deei_active_complete_run_id'), 'forced digest collision rolls back terminal batch without merging or promotion');
wp_delete_post($collision_id, true);

$many = '';
for ($i = 0; $i < 201; ++$i) {
    $many .= '<img src="https://h' . $i . '.example/x">';
}
$many_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'many hosts', 'post_content' => $many), true);
$new = DEEI_Run::start();
DEEI_Run::proceed();
$complete = DEEI_Run::proceed();
$assert(is_array($complete) && 'complete' === $complete['status'], '201-host report completes within candidate cap');
$row_queries = 0;
$late_fault = static function (string $sql) use (&$row_queries, $invalid_sql): string {
    if (str_contains($sql, 'deei_rows') && str_contains($sql, 'ORDER BY r.id ASC LIMIT 200')) {
        ++$row_queries;
        return $row_queries >= 2 ? $invalid_sql : $sql;
    }
    return $sql;
};
add_filter('query', $late_fault);
$stream = tmpfile();
$csv_ok = DEEI_Admin::stream_csv($stream, $complete);
fclose($stream);
remove_filter('query', $late_fault);
$assert(false === $csv_ok && $row_queries >= 2, 'later CSV page SQL failure aborts spool');

$csv_coverage_fault = static fn(string $sql): string => str_contains($sql, 'deei_coverage') && str_contains($sql, 'SELECT r.*') ? $invalid_sql : $sql;
add_filter('query', $csv_coverage_fault);
$stream = tmpfile();
$csv_ok = DEEI_Admin::stream_csv($stream, $complete);
fclose($stream);
remove_filter('query', $csv_coverage_fault);
$assert(false === $csv_ok, 'coverage SQL failure aborts CSV before download');

$wpdb->suppress_errors(false);
$assert(DEEI_Storage::transactional(), 'all plugin tables use InnoDB');
echo wp_json_encode(array('status' => 'pass', 'checks' => $checks, 'active_run' => $active, 'incomplete_run' => $b2['run_id']), JSON_PRETTY_PRINT) . PHP_EOL;
