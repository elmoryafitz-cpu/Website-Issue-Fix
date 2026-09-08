<?php
if (!defined('ABSPATH')) { exit; }

class GSCSF_Google {
    public static function configured() {
        return defined('GSCSF_SERVICE_ACCOUNT_FILE') && GSCSF_SERVICE_ACCOUNT_FILE !== '';
    }

    private static function token() {
        $cached = get_transient('gscsf_google_token');
        if ($cached) { return $cached; }
        if (!self::configured() || !function_exists('openssl_sign')) { return new WP_Error('setup', 'Configure the service account and PHP OpenSSL.'); }
        // This path is trusted server configuration, never an AJAX parameter or uploaded public file.
        $path = realpath(GSCSF_SERVICE_ACCOUNT_FILE);
        if (!$path || !is_readable($path) || filesize($path) > 32768) { return new WP_Error('key', 'Service-account key is not readable.'); }
        $key = json_decode(file_get_contents($path), true);
        if (!is_array($key) || ($key['type'] ?? '') !== 'service_account' || empty($key['client_email']) || empty($key['private_key'])) {
            return new WP_Error('key', 'Invalid service-account configuration.');
        }
        $encode = function ($text) { return rtrim(strtr(base64_encode($text), '+/', '-_'), '='); };
        $now = time();
        $jwt = $encode(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))) . '.' . $encode(wp_json_encode(array(
            'iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        )));
        $signature = '';
        if (!openssl_sign($jwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) { return new WP_Error('key', 'Could not sign the service-account token.'); }
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 8, 'redirection' => 0, 'body' => array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . $encode($signature),
        )));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return new WP_Error('auth', 'Google authentication failed. Check the service account and server clock.'); }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) { return new WP_Error('auth', 'Google did not return an access token.'); }
        set_transient('gscsf_google_token', $body['access_token'], max(60, min(3300, (int) ($body['expires_in'] ?? 3600) - 60)));
        return $body['access_token'];
    }

    public static function inspect($url, $property) {
        if (!self::configured() || !$property) { return array(); }
        $cache_key = 'gscsf_i_' . md5($property . '|' . $url);
        $cached = get_transient($cache_key);
        if (is_array($cached)) { return $cached; }
        $skip = function ($message) { return array(GSCSF_Audit::issue('gsc_unavailable', $message, false, 'info', 'google')); };
        if (get_transient('gscsf_google_backoff')) { return $skip('Google inspection deferred after an API/authentication error. Local checks still ran. Retry in a later scan.'); }
        $quota_key = 'gscsf_q_' . md5($property);
        $quota = get_option($quota_key, array('start' => time(), 'count' => 0));
        if (time() - $quota['start'] >= DAY_IN_SECONDS) { $quota = array('start' => time(), 'count' => 0); }
        if ($quota['count'] >= 1900) { return $skip('Local 1,900 inspections per rolling 24-hour budget reached. This URL was not inspected by Google; local checks still ran.'); }
        $token = self::token();
        if (is_wp_error($token)) {
            set_transient('gscsf_google_backoff', 1, 900);
            return $skip($token->get_error_message());
        }
        $quota['count']++;
        update_option($quota_key, $quota, false);
        $response = wp_remote_post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', array(
            'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 524288,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('inspectionUrl' => $url, 'siteUrl' => $property, 'languageCode' => 'en-US')),
        ));
        $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            if ($status === 401) { delete_transient('gscsf_google_token'); }
            set_transient('gscsf_google_backoff', 1, $status === 429 ? HOUR_IN_SECONDS : 900);
            return $skip('Google inspection failed (HTTP ' . $status . '). Check API enablement, property permissions and quotas. No Google status was inferred.');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['inspectionResult']) || !is_array($body['inspectionResult'])) { return $skip('Google returned an incomplete inspection response.'); }
        $result = $body['inspectionResult'];
        $out = array();
        $index = $result['indexStatusResult'] ?? array();
        if ($index) {
            $out[] = GSCSF_Audit::issue('gsc_index', 'Google indexed snapshot: ' . ($index['coverageState'] ?? 'unknown coverage') . '; verdict: ' . ($index['verdict'] ?? 'unknown') . '; last crawl: ' . ($index['lastCrawlTime'] ?? 'not supplied') . '. Local repairs do not change this snapshot immediately.', false, ($index['verdict'] ?? '') === 'FAIL' ? 'warning' : 'info', 'google');
            foreach (array('robotsTxtState', 'indexingState', 'pageFetchState') as $field) {
                if (!empty($index[$field]) && !in_array($index[$field], array('ALLOWED', 'INDEXING_ALLOWED', 'SUCCESSFUL'), true)) {
                    $out[] = GSCSF_Audit::issue('gsc_' . $field, $field . ': ' . $index[$field] . '. Review the indexed snapshot against current local settings.', false, 'warning', 'google');
                }
            }
            if (!empty($index['googleCanonical']) && untrailingslashit($index['googleCanonical']) !== untrailingslashit($url)) {
                $out[] = GSCSF_Audit::issue('gsc_canonical', 'Google-selected canonical: ' . $index['googleCanonical'] . '. Review duplicate content and canonical signals. Google makes the final selection.', false, 'info', 'google');
            }
        }
        foreach ($result['richResultsResult']['detectedItems'] ?? array() as $group) {
            foreach ($group['items'] ?? array() as $item) {
                foreach ($item['issues'] ?? array() as $issue) {
                    $message = ($group['richResultType'] ?? 'Rich result') . ': ' . ($issue['issueMessage'] ?? 'Unspecified issue') . '. Correct factual data in the source schema provider, then validate in Search Console.';
                    $out[] = GSCSF_Audit::issue('gsc_rich_' . md5($message), $message, false, ($issue['severity'] ?? '') === 'ERROR' ? 'error' : 'warning', 'google');
                }
            }
        }
        if (!$out) { $out = $skip('No index or rich-result details were returned for this URL.'); }
        set_transient($cache_key, $out, DAY_IN_SECONDS);
        return $out;
    }
}
