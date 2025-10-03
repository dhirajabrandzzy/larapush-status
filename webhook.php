<?php

// Webhook handler for GitHub deployments to status.larapush.com
// Place this file in your web root (/home/status.larapush.com/public_html/)

// Configure THESE IN .env 
// GITHUB_WEBHOOK_SECRET=

// Administrative variables for enabling/disabling functionalities
$enableGithubAuthentication = true; // Set to false to disable GitHub authentication
$enableGitPull = true; // Set to false to disable git pull
$enableCacheClear = true; // Set to false to disable clearing proxy cache
$logDeployments = true; // Set to true to log deployment attempts

// The path to your .env file
$envFilePath = __DIR__ . '/.env';

// Check if the .env file exists
if (!file_exists($envFilePath)) {
    http_response_code(500);
    die('Webhook not configured properly - .env file missing');
}

// Read and parse the .env file
$envVars = parse_ini_file($envFilePath, false, INI_SCANNER_RAW);

$github_webhook_secret = $envVars['GITHUB_WEBHOOK_SECRET'] ?? '';

function verifyGithubWebhookSignature($secret)
{
    if (empty($secret)) {
        return false;
    }
    
    $payload = file_get_contents('php://input');
    $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

    if (empty($signatureHeader)) {
        return false;
    }

    // GitHub sends the hash signature with prefix 'sha256=', so we need to split it.
    list($algo, $githubSignature) = explode('=', $signatureHeader, 2);

    // Compute the hash of the payload with the secret.
    $payloadHash = hash_hmac('sha256', $payload, $secret);

    // Securely compare the computed hash against the GitHub provided hash.
    return hash_equals($githubSignature, $payloadHash);
}

function pullLatestChanges($repoPath = null)
{
    $repoPath = $repoPath ?: __DIR__;
    
    // Set git config for headless environment and safe directory
    putenv('HOME=/tmp');
    putenv('GIT_ORIGIN=true');
    
    // Ensure safe directory is set for this repository
    $safeDirCommand = sprintf('cd %s && git config --global --add safe.directory %s 2>&1', escapeshellarg($repoPath), escapeshellarg($repoPath));
    shell_exec($safeDirCommand);
    
    // Change to repo directory and pull changes
    $command = sprintf('cd %s && /usr/bin/git pull origin main 2>&1', escapeshellarg($repoPath));
    $output = shell_exec($command);
    
    $logMessage = "Git pull output:\n" . $output . "\n";
    logMessage($logMessage);
    
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
        
        $logMessage = "Cleared {$deleted} cache files from proxy cache\n";
        logMessage($logMessage);
    }
}


function logMessage($message)
{
    global $logDeployments;
    
    if ($logDeployments) {
        $logFile = __DIR__ . '/deployment.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}", FILE_APPEND | LOCK_EX);
    }
}

function main()
{
    global $enableGithubAuthentication, $github_webhook_secret, $enableGitPull, $enableCacheClear;

    logMessage("=== New deployment request received ===\n");
    logMessage("Request method: " . $_SERVER['REQUEST_METHOD'] . "\n");
    logMessage("Headers: " . json_encode(getallheaders()) . "\n");

    if ($enableGithubAuthentication) {
        if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
            logMessage("ERROR: Missing GitHub signature header\n");
            http_response_code(400);
            die('Invalid Request - Missing signature');
        }

        if (!verifyGithubWebhookSignature($github_webhook_secret)) {
            logMessage("ERROR: Invalid GitHub signature\n");
            http_response_code(403);
            die('Invalid Signature');
        }
        
        logMessage("GitHub signature verified successfully\n");
    }

    // Respond to GitHub with 200 OK immediately
    http_response_code(200);
    ignore_user_abort(true);
    ob_start();
    echo "Webhook received. Processing deployment...";
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    ob_end_flush();
    flush();

    logMessage("Starting deployment process\n");

    if ($enableGitPull) {
        logMessage("Pulling latest changes from GitHub\n");
        pullLatestChanges();
    }

    if ($enableCacheClear) {
        logMessage("Clearing proxy cache\n");
        clearProxyCache();
    }

    logMessage("=== Deployment completed ===\n\n");
}

main();
?>
