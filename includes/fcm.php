<?php
// ============================================================
// BraidedbyAGB — FCM Push Notifications (HTTP v1 API)
// FILE: /includes/fcm.php
//
// Uses the service account JSON + RS256 JWT to get an OAuth2
// access token, then sends via FCM HTTP v1.
// No external libraries required — pure PHP + curl + openssl.
// ============================================================

function _fcm_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _fcm_get_access_token(): string {
    // Cache in a temp file to avoid re-generating on every request
    $cacheFile = sys_get_temp_dir() . '/braidedbyagb_fcm_token.json';
    if (file_exists($cacheFile)) {
        $cached = @json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['exp']) && $cached['exp'] > time() + 120) {
            return $cached['token'];
        }
    }

    // Load service account (handle both normal and double-dot filename)
    $saPath = __DIR__ . '/../config/firebase-service-account.json';
    if (!file_exists($saPath)) {
        $saPath = __DIR__ . '/../config/firebase-service-account..json';
    }
    if (!file_exists($saPath)) {
        throw new RuntimeException('FCM: service account file not found');
    }
    $sa = json_decode(file_get_contents($saPath), true);
    if (!$sa || empty($sa['private_key'])) {
        throw new RuntimeException('FCM: invalid service account JSON');
    }

    $now     = time();
    $header  = _fcm_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = _fcm_base64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signingInput = "$header.$payload";
    $key = openssl_pkey_get_private($sa['private_key']);
    if (!$key) throw new RuntimeException('FCM: failed to load private key');
    openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
    $jwt = "$signingInput." . _fcm_base64url($signature);

    // Exchange JWT for Google OAuth2 access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException('FCM: token exchange failed (' . $code . '): ' . $res);
    }
    $data  = json_decode($res, true);
    $token = $data['access_token'] ?? '';
    if (!$token) throw new RuntimeException('FCM: empty access token in response');

    // Cache for ~58 minutes
    @file_put_contents($cacheFile, json_encode(['token' => $token, 'exp' => $now + 3500]));
    return $token;
}

/**
 * Send a push notification to all registered FCM device tokens.
 *
 * @param string $title   Notification title (shown in system tray)
 * @param string $body    Notification body text
 * @param array  $data    Optional key=>value data payload (all values become strings)
 * @return bool           true if at least one message was delivered successfully
 */
function fcm_send(string $title, string $body, array $data = []): bool {
    try {
        $db = getDB();

        // Ensure fcm_tokens table exists
        $db->exec("CREATE TABLE IF NOT EXISTS fcm_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(512) NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $tokens = $db->query("SELECT token FROM fcm_tokens ORDER BY updated_at DESC LIMIT 10")
                     ->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tokens)) return false;

        $accessToken = _fcm_get_access_token();
        $projectId   = 'braidedbyagb-bda33';
        $url         = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $delivered   = false;

        foreach ($tokens as $token) {
            $payload = json_encode([
                'message' => [
                    'token'        => $token,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    'data'    => array_map('strval', $data),
                    'android' => [
                        'priority'     => 'high',
                        'notification' => ['channel_id' => 'live_chat'],
                    ],
                ],
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $result = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $delivered = true;
            } else {
                // Clean up invalid/unregistered tokens
                $resp    = json_decode($result, true);
                $errCode = $resp['error']['details'][0]['errorCode'] ?? '';
                if (in_array($errCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                    $db->prepare("DELETE FROM fcm_tokens WHERE token=?")->execute([$token]);
                }
                error_log("FCM send error (HTTP $code): $result");
            }
        }
        return $delivered;
    } catch (Throwable $e) {
        error_log('fcm_send() error: ' . $e->getMessage());
        return false;
    }
}
