<?php
// access.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = $_SERVER['QUERY_STRING'] ?? '';

function proxyRequest($targetUrl, $useCache = false)
{
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = null;
    $cacheTtlSeconds = 15 * 60; // 15 minutes

    if ($useCache) {
        // Create cache filename from URL
        $cacheKey = md5($targetUrl);
        $cacheFile = $cacheDir . '/' . $cacheKey;

        // Check if cache exists and is fresh
        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtlSeconds)) {
            $cachedData = file_get_contents($cacheFile);
            if ($cachedData !== false) {
                $data = unserialize($cachedData);
                if ($data && isset($data['headers'], $data['body'])) {
                    // Set cached headers
                    foreach ($data['headers'] as $header) {
                        header($header);
                    }
                    echo $data['body'];
                    return;
                }
            }
        }
    }

    // Fetch from upstream
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $targetUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'StatusProxy/1.0',
    ));

    // Forward request headers
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $header = str_replace('_', '-', substr($key, 5));
            $headerLower = strtolower($header);
            // Skip problematic headers
            if (!in_array($headerLower, ['host', 'connection', 'accept-encoding', 'content-length', 'transfer-encoding'])) {
                $headers[] = $header . ': ' . $value;
            }
        }
    }
    if ($headers) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $curlErr = curl_error($curl);
    curl_close($curl);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);


        // Parse and set response headers
        $headerLines = explode("\r\n", trim($responseHeaders));
        $processedHeaders = [];

        foreach ($headerLines as $header) {
            if (strpos($header, ':') !== false) {
                $headerLower = strtolower($header);
                // Skip problematic headers that can cause issues
                if (!preg_match('/^(transfer-encoding|connection|content-encoding|content-length):/i', $header)) {
                    header($header);
                    $processedHeaders[] = $header;
                }
            }
        }

        // Cache the response if caching is enabled
        if ($useCache && $cacheFile) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0777, true);
            }
            $cacheData = serialize([
                'headers' => $processedHeaders,
                'body' => $responseBody
            ]);
            @file_put_contents($cacheFile, $cacheData);
        }

        echo $responseBody;
    } else {
        http_response_code($httpCode ?: 502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Upstream fetch failed';
        if ($curlErr) {
            echo "\nCURL Error: " . $curlErr;
        }
        if ($httpCode) {
            echo "\nHTTP Status: " . $httpCode;
        }
        echo "\nTarget URL: " . $targetUrl;

        // For debugging, show partial response if available
        if ($response !== false && strlen($response) > 0) {
            echo "\nPartial Response: " . substr($response, 0, 500) . "...";
        }
    }
}

// Check if it's a status.json request (real-time, no cache)
if (strpos($uri, '/status.json') !== false) {
    $targetUrl = 'https://larapush.statuspage.io' . $uri;
    if ($query) {
        $targetUrl .= '?' . $query;
    }
    proxyRequest($targetUrl, false);
} else {
    // All other requests use cache
    $targetUrl = 'https://larapush.statuspage.io' . $uri;
    if ($query) {
        $targetUrl .= '?' . $query;
    }
    proxyRequest($targetUrl, true);
}
