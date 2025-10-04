<?php

// Improved webhook handler for GitHub deployments to status.larapush.com
// Based on professional CI/CD patterns

// Configuration constants
const ENV_FILE_PATH = __DIR__ . '/.env';
const GIT_DIRECTORY = __DIR__;
const LOG_FILE_PATH = __DIR__ . '/deployment.log';

// Administrative variables for enabling/disabling functionalities
const ENABLE_GITHUB_AUTHENTICATION = true;
const ENABLE_PULL = true;
const ENABLE_CACHE_CLEAR = true;
const ENABLE_GITHUB_BRANCH_CHECK = true;
const LOG_DEPLOYMENTS = true;

// Maximum execution time and memory limits
const MAX_EXECUTION_TIME = 60;
const MAX_MEMORY_LIMIT = '128M';

// Check if the .env file exists
if (!file_exists(ENV_FILE_PATH)) {
    http_response_code(500);
    exit('Webhook not configured properly - .env file missing');
}

// Read and parse the .env file
$envVars = parse_ini_file(ENV_FILE_PATH, false, INI_SCANNER_RAW);
$github_webhook_secret = $envVars['GITHUB_WEBHOOK_SECRET'] ?? '';

// Set execution limits
ini_set('max_execution_time', MAX_EXECUTION_TIME);
ini_set('memory_limit', MAX_MEMORY_LIMIT);
ini_set('display_errors', 0);
error_reporting(E_ALL);

function writeToLog($message)
{
    global $LOG_DEPLOYMENTS;
    
    if ($LOG_DEPLOYMENTS) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents(LOG_FILE_PATH, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    }
}

function getCurrentBranch()
{
    $command = sprintf('cd %s && /usr/bin/git rev-parse --abbrev-ref HEAD 2>&1', escapeshellarg(GIT_DIRECTORY));
    $branch = shell_exec($command);
    writeToLog("Current branch: " . trim($branch));
    return trim($branch);
}

function verifyGithubWebhookSignature($secret)
{
    if (empty($secret)) {
        writeToLog("GitHub webhook secret not set");
        return false;
    }
    
    $payload = file_get_contents('php://input');
    
    if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
        writeToLog("Missing X-Hub-Signature-256 header");
        return false;
    }

    // GitHub sends the hash signature with prefix 'sha256=', so we need to split it.
    list($algo, $githubSignature) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE_256'], 2);

    // Compute the hash of the payload with the secret.
    $payloadHash = hash_hmac('sha256', $payload, $secret);

    // Securely compare the computed hash against the GitHub provided hash.
    $matches = hash_equals($githubSignature, $payloadHash);
    
    if (!$matches) {
        writeToLog("Signature verification failed");
        writeToLog("Expected: $githubSignature");
        writeToLog("Got: $payloadHash");
    }
    
    return $matches;
}

function pullLatestChanges()
{
    // Set git config for headless environment and SSH
    putenv('HOME=/root'); // Use root's home directory where SSH keys are
    putenv('GIT_ORIGIN=true');
    
    // Ensure safe directory is set for this repository
    $safeDirCommand = sprintf('cd %s && git config --global --add safe.directory %s 2>&1', escapeshellarg(GIT_DIRECTORY), escapeshellarg(GIT_DIRECTORY));
    shell_exec($safeDirCommand);
    
    // Set SSH to use the deploy key specifically
    $sshCommand = sprintf('cd %s && GIT_SSH_COMMAND="ssh -i /root/.ssh/larapush_deploy_key -o StrictHostKeyChecking=no" /usr/bin/git pull origin main 2>&1', escapeshellarg(GIT_DIRECTORY));
    $output = shell_exec($sshCommand);
    
    writeToLog("Git pull output:\n" . $output);
    return $output;
}

function clearProxyCache()
{
    $cacheDir = GIT_DIRECTORY . '/cache';
    
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $deleted++;
            }
        }
        
        writeToLog("Cleared $deleted cache files from proxy cache");
    } else {
        writeToLog("Cache directory does not exist: $cacheDir");
    }
}

function main()
{
    global $github_webhook_secret;

    writeToLog('=== GitHub webhook received ===');
    writeToLog("Request method: " . $_SERVER['REQUEST_METHOD']);
    
    // Get the raw payload
    $rawPayload = file_get_contents('php://input');
    writeToLog("Payload length: " . strlen($rawPayload));

    if (ENABLE_GITHUB_AUTHENTICATION) {
        if (!verifyGithubWebhookSignature($github_webhook_secret)) {
            http_response_code(403);
            writeToLog("Authentication failed");
            exit('Invalid Signature');
        }
        writeToLog("GitHub signature verified successfully");
    }

    if (ENABLE_GITHUB_BRANCH_CHECK) {
        $currentBranch = getCurrentBranch();
        $payload = json_decode($rawPayload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeToLog("Failed to parse JSON payload: " . json_last_error_msg());
            http_response_code(400);
            exit('Invalid JSON payload');
        }
        
        if (!isset($payload['ref'])) {
            writeToLog("Missing 'ref' in payload");
            http_response_code(400);
            exit('Invalid payload format');
        }
        
        $payloadBranch = explode('/', $payload['ref'])[2] ?? '';
        
        if ($currentBranch !== $payloadBranch) {
            writeToLog("Push made to $payloadBranch, but current branch is $currentBranch - skipping deployment");
            exit('Branch mismatch');
        }
        
        writeToLog("Branch check passed: $currentBranch");
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

    writeToLog("Starting deployment process");

    if (ENABLE_PULL) {
        writeToLog("Pulling latest changes from GitHub");
        pullLatestChanges();
    }

    if (ENABLE_CACHE_CLEAR) {
        writeToLog("Clearing proxy cache");
        clearProxyCache();
    }

    writeToLog("=== Deployment completed successfully ===");
}

main();
?>
