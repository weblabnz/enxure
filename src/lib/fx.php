<?php
// ── FX Rate Conversion ──────────────────────────────────────────────────────
// Backs "blend other-currency invoices into one converted total" in
// Statistics, Forecasting, and AR Aging (see enxure.php's Data Fetching
// section). Rates are cached in invoxa_settings (fx_rates_json/fx_rates_base/
// fx_rates_fetched_at) and refreshed at most once every 24 hours. A failed
// fetch falls back to the last cached rates if any exist, or an empty rate
// set otherwise — enxureSumByCcyConverted() already treats "no rate for this
// currency" as "exclude it," the same behavior Statistics had before this
// feature existed, so a stale/missing rate never blends in a wrong number.

define('FX_RATES_CACHE_HOURS', 24);

// Builds the provider request URL. {base} and {symbols} are substituted;
// symbols is a comma-joined list of the foreign currency codes actually
// needed, so the request stays small even against a custom provider.
function enxureFxRequestUrl(array $settings, string $baseCcy, array $symbols): string
{
    $provider = $settings['fx_provider'] ?? 'frankfurter';
    $template = ($provider === 'custom' && !empty($settings['fx_custom_url']))
        ? $settings['fx_custom_url']
        : 'https://api.frankfurter.dev/v1/latest?base={base}&symbols={symbols}';
    return str_replace(['{base}', '{symbols}'], [rawurlencode($baseCcy), rawurlencode(implode(',', $symbols))], $template);
}

// Fetches fresh rates from the configured provider. Returns a flat
// [currencyCode => rate] map — rate is how many units of that currency equal
// 1 unit of $baseCcy, the same shape Frankfurter itself returns — on success,
// or null on any failure (unreachable, non-2xx, unexpected JSON shape). Never
// throws: a broken FX fetch must not break the Statistics page it feeds.
function enxureFxFetchRates(array $settings, string $baseCcy, array $symbols): ?array
{
    if (empty($symbols)) {
        return [];
    }
    $url = enxureFxRequestUrl($settings, $baseCcy, $symbols);
    $headers = [];
    if (($settings['fx_provider'] ?? 'frankfurter') === 'custom' && !empty($settings['fx_custom_api_key'])) {
        $headers['Authorization'] = 'Bearer ' . $settings['fx_custom_api_key'];
    }
    $result = httpApiRequest($url, 'GET', $headers, null);
    if (!$result['success'] || !is_array($result['body']['rates'] ?? null)) {
        return null;
    }
    $rates = [];
    foreach ($result['body']['rates'] as $ccy => $rate) {
        $rates[enxureNormalizeCurrencyCode((string) $ccy)] = (float) $rate;
    }
    return $rates;
}

// Cached, at-most-daily wrapper around enxureFxFetchRates(). $symbols is the
// set of non-default currencies actually present in the data right now — a
// currency that wasn't in the cached set forces a fresh fetch even within
// the 24h window, so a newly-added currency doesn't sit unconverted until
// the cache next expires on its own.
function enxureGetFxRates($mysqli, array $settings, string $baseCcy, array $symbols): array
{
    if (empty($symbols)) {
        return [];
    }
    sort($symbols);
    $cached = ($settings['fx_rates_json'] ?? '') !== '' ? json_decode($settings['fx_rates_json'], true) : null;
    $cachedBase = $settings['fx_rates_base'] ?? '';
    $cachedAt = $settings['fx_rates_fetched_at'] ?? '';
    $cachedSymbols = is_array($cached) ? array_keys($cached) : [];
    sort($cachedSymbols);
    $isFresh = $cachedAt !== '' && (time() - strtotime($cachedAt)) < FX_RATES_CACHE_HOURS * 3600;

    if (is_array($cached) && $isFresh && $cachedBase === $baseCcy && $cachedSymbols === $symbols) {
        return $cached;
    }

    $fresh = enxureFxFetchRates($settings, $baseCcy, $symbols);
    if ($fresh === null) {
        return is_array($cached) ? $cached : [];
    }

    $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'fx_rates_json' => json_encode($fresh),
        'fx_rates_base' => $baseCcy,
        'fx_rates_fetched_at' => date('Y-m-d H:i:s'),
    ] as $key => $value) {
        $upsert->bind_param('ss', $key, $value);
        $upsert->execute();
    }
    return $fresh;
}

// Blends a per-currency amount map (as returned by enxureGroupAmountsByCurrency()
// / enxureGroupRowsByCurrency()) into one total in $defaultCcy, using $fxRates
// (currency code => units of that currency per 1 unit of $defaultCcy). A
// currency with no available rate is excluded from the sum entirely — the
// same behavior Statistics/Forecasting/AR Aging had before FX conversion
// existed — rather than blended in unconverted, which would silently corrupt
// the total.
function enxureSumByCcyConverted(array $byCcy, string $defaultCcy, array $fxRates): float
{
    $total = 0.0;
    foreach ($byCcy as $ccy => $amount) {
        if ($ccy === $defaultCcy) {
            $total += (float) $amount;
        } elseif (isset($fxRates[$ccy]) && $fxRates[$ccy] > 0) {
            $total += (float) $amount / $fxRates[$ccy];
        }
    }
    return $total;
}
