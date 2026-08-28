<?php
/**
 * Invoxa license verification.
 *
 * Invoxa is open source (AGPL-3.0, see ../../LICENSE) and ships as plain,
 * readable source. The license key doesn't gate access to the source, only
 * a handful of paid features at runtime (see invoxa.php).
 *
 * Offline check only, no network calls. A license is a single string:
 *   base64(email|domain|issued_date) + '.' + base64(ed25519 signature)
 * The signature is produced by generate_license.php using a private key
 * that is never shipped to buyers; this file only holds the public key.
 *
 * Two identity anchors are required: the request domain and the email on
 * the original account — the one created via the signup screen, i.e. the
 * lowest id in invoxa_users, regardless of how many teammates get added
 * later via Settings > Users — must both match what the license was issued
 * for.
 *
 * This is a deterrent against casual copying, not DRM — a buyer who
 * controls their own server can patch this check out under their AGPL
 * rights. The paid features are a courtesy tied to support/updates, not a
 * technical lock.
 */

define('INVOXA_LICENSE_PUBLIC_KEY_B64', 'JkJJB397P8ayL0GbfAUDQ/bZRJxNgsqSlS4wcN7KuJo=');

/**
 * @param bool $skipDomainCheck Pass true only for requests already authenticated
 *   by another trusted secret (the cron_key check for recurring billing) — those
 *   requests hit the app via the internal Docker hostname, not the buyer's real
 *   domain, so HTTP_HOST can't be checked there. The signature is still verified
 *   either way; only domain-binding is skipped.
 * @param ?string $reason Out param, set to why an invalid license failed, for
 *   display in Settings > License. Informational only, not used in the pass/fail
 *   decision.
 */
function licenseIsValid($mysqli, array $settings, bool $skipDomainCheck = false, ?string &$reason = null): bool
{
    // Public demo instances set this so paid features can never be unlocked,
    // even with a valid key — the check below never reads the license_key.
    if (getenv('INVOXA_DEMO_MODE')) {
        $reason = 'demo_mode';
        return false;
    }

    $reason = 'empty';
    $license = trim($settings['license_key'] ?? '');
    if ($license === '') {
        return false;
    }

    $reason = 'malformed';
    $parts = explode('.', $license, 2);
    if (count($parts) !== 2) {
        return false;
    }
    [$payloadB64, $sigB64] = $parts;

    $payload = base64_decode($payloadB64, true);
    $signature = base64_decode($sigB64, true);
    $publicKey = base64_decode(INVOXA_LICENSE_PUBLIC_KEY_B64, true);
    if ($payload === false || $signature === false || $publicKey === false) {
        return false;
    }
    if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return false;
    }

    $reason = 'bad_signature';
    if (!sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
        return false;
    }

    $reason = 'malformed';
    $fields = explode('|', $payload);
    if (count($fields) !== 3) {
        return false;
    }
    [$email, $licensedDomain, $issuedDate] = $fields;

    // The original account's email (Settings > Account) must match the email
    // the license was issued to — always the lowest id, not whichever user is
    // currently logged in (or no one, on the cron path). Applies to both the
    // browser and cron paths (unlike the domain check below, it doesn't
    // depend on $skipDomainCheck).
    $userRes = $mysqli->query("SELECT email FROM invoxa_users ORDER BY id ASC LIMIT 1");
    $profileEmail = $userRes ? trim((string) ($userRes->fetch_assoc()['email'] ?? '')) : '';
    if ($profileEmail === '') {
        $reason = 'no_profile_email';
        return false;
    }
    if (strcasecmp($profileEmail, trim($email)) !== 0) {
        $reason = 'email_mismatch';
        return false;
    }

    if ($skipDomainCheck) {
        $reason = 'valid';
        return true;
    }

    $requestHost = invoxaNormaliseDomain($_SERVER['HTTP_HOST'] ?? '');
    if ($requestHost !== '' && $requestHost === invoxaNormaliseDomain($licensedDomain)) {
        $reason = 'valid';
        return true;
    }
    $reason = 'domain_mismatch';
    return false;
}

function invoxaNormaliseDomain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/:\d+$/', '', $domain);   // strip port
    $domain = preg_replace('/^www\./', '', $domain);  // strip leading www.
    return $domain;
}
