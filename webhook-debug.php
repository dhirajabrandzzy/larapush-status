<?php

// Debug webhook handler for GitHub deployments to status.larapush.com
// This version includes detailed logging to help debug signature issues

// Administrative variables for enabling/disabling functionalities
$enableGithubAuthentication = true; // Set to false to disable GitHub authentication
$enableGitPull = true; // Set to false to disable git pull
$enableCacheClear = true; // Set to false to disable clearing proxy cache
$logDeployments = true; // Set to true to log deployment attempts

// The path to your .env file
$envFilePath = __DIR__ . '/.env';

debugLog("DEBUG: Looking for .env file at: " . $envFilePath);
debugLog("DEBUG: Current directory: " . __DIR__);

// Check if the .env file exists
if (!file_exists($envFilePath)) {
    debugLog("ERROR: .env file does not exist at: " . $envFilePath);
    debugLog("DEBUG: Files in current directory:");
    $files = scandir(__DIR__);
    foreach ($files as $file) {
        debugLog("  - " . $file);
    }
    http_response_code(500);
    die('Webhook not configured properly - .env file missing');
}

debugLog("DEBUG: .env file exists");

// Check file permissions
$perms = fileperms($envFilePath);
debugLog("DEBUG: .env file permissions: " . sprintf('%o', $perms));
debugLog("DEBUG: File readable by web server: " . (is_readable($envFilePath) ? 'YES' : 'NO'));

// Read and parse the .env file
debugLog("DEBUG: Reading .env file...");
$envRawContent = file_get_contents($envFilePath);
debugLog("DEBUG: Raw .env content length: " . strlen($envRawContent));
debugLog("DEBUG: Raw .env content (first 100 chars): " . substr($envRawContent, 0, 100));

$envVars = parse_ini_file($envFilePath, false, INI_SCANNER_RAW);
debugLog("DEBUG: Parsed env vars count: " . count($envVars));
debugLog("DEBUG: Available env vars: " . implode(', ', array_keys($envVars)));

$github_webhook_secret = $envVars['GITHUB_WEBHOOK_SECRET'] ?? '';
debugLog("DEBUG: Retrieved secret length: " . strlen($github_webhook_secret));

function debugLog($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/debug.log';
    $debugMessage = "[{$timestamp}] {$message}\n";
    
    // Also output to response (for debugging)
    echo $debugMessage;
    
    // Log to file
    file_put_contents($logFile, $debugMessage, FILE_APPEND | LOCK_EX);
}

function verifyGithubWebhookSignature($secret)
{
    debugLog("DEBUG: Starting signature verification");
    debugLog("DEBUG: Secret length: " . strlen($secret));
    
    if (empty($secret)) {
        debugLog("ERROR: Empty secret in .env file");
        return false;
    }
    
    $payload = file_get_contents('php://input');
    debugLog("DEBUG: Payload length: " . strlen($payload));
    
    $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    debugLog("DEBUG: Signature header: " . $signatureHeader);
    
    if (empty($signatureHeader)) {
        debugLog("ERROR: Missing X-Hub-Signature-256 header");
        return false;
    }

    // GitHub sends the hash signature with prefix 'sha256=', so we need to split it.
    if (strpos($signatureHeader, '=') === false) {
        debugLog("ERROR: Invalid signature header format");
        return false;
    }
    
    list($algo, $githubSignature) = explode('=', $signatureHeader, 2);
    debugLog("DEBUG: Algorithm: " . $algo);
    debugLog("DEBUG: GitHub signature: " . $githubSignature);

    // Compute the hash of the payload with the secret.
    $payloadHash = hash_hmac('sha256', $payload, $secret);
    debugLog("DEBUG: Computed hash: " . $payloadHash);

    // Compare the signatures
    $matches = hash_equals($githubSignature, $payloadHash);
    debugLog("DEBUG: Signatures match: " . ($matches ? 'YES' : 'NO'));
    
    if (!$matches) {
        debugLog("ERROR: Signature mismatch");
        debugLog("Expected: " . $githubSignature);
        debugLog("Got: " . $payloadHash);
    }
    
    return $matches;
}

function pullLatestChanges($repoPath = null)
{
    $repoPath = $repoPath ?: __DIR__;
    
    // Set git config for headless environment and safe directory
    putenv('HOME=/tmp');
    putenv('GIT_ORIGIN=true');
    
    // Ensure safe directory is set for this repository
    $safeDirCommand = sprintf('cd %s && git config --global --add safe.directory %s 2>&1', escapeshellarg($repoPath), escapeshellarg($repoPath));
    $safeDirOutput = shell_exec($safeDirCommand);
    debugLog("Git safe directory command output: " . $safeDirOutput);
    
    // Change to repo directory and pull changes
    $command = sprintf('cd %s && /usr/bin/git pull origin main 2>&1', escapeshellarg($repoPath));
    $output = shell_exec($command);
    
    debugLog("Git pull output: " . $output);
    return $output;
}

function clearProxyCache()
{
    $cacheDir = __DIR__ . '/cache';
    
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $deleted++;
            }
        }
        
        debugLog("Cleared {$deleted} cache files from proxy cache");
    }
}

function main()
{
    global $enableGithubAuthentication, $github_webhook_secret, $enableGitPull, $enableCacheClear;

    debugLog("=== DEBUG WEBHOOK REQUEST ===");
    debugLog("Request method: " . $_SERVER['REQUEST_METHOD']);
    debugLog("Headers received:");
    foreach (getallheaders() as $name => $value) {
        debugLog("  {$name}: {$value}");
    }
    
    debugLog("Configured secret (first 10 chars): " . substr($github_webhook_secret, 0, 10) . "...");

    if ($enableGithubAuthentication) {
        if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
            debugLog("ERROR: Missing GitHub signature header");
            debugLog("Available headers: " . implode(', ', array_keys($_SERVER)));
            http_response_code(400);
            die('Invalid Request - Missing signature');
        }

        if (!verifyGithubWebhookSignature($github_webhook_secret)) {
            debugLog("ERROR: Invalid GitHub signature");
            http_response_code(403);
            die('Invalid Signature');
        }
        
        debugLog("GitHub signature verified successfully");
    }

    // Respond to GitHub with 200 OK immediately
    http_response_code(200);
    ignore_user_abort(true);
    ob_start();
    echo "Debug webhook received. Processing deployment...";
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    echo "<br><br>DEBUG OUTPUT:<br>";
    
    debugLog("Starting deployment process");

    if ($enableGitPull) {
        debugLog("Pulling latest changes from GitHub");
        pullLatestChanges();
    }

    if ($enableCacheClear) {
        debugLog("Clearing proxy cache");
        clearProxyCache();
    }

    debugLog("=== Deployment completed ===");
    
    header('Content-Length: ' . ob_get_length());
    ob_end_flush();
    flush();
}

main();
?>
