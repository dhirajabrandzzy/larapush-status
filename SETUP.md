# Larapush Status CI/CD Setup

This repository contains the necessary files to set up automated deployments for your Larapush status page using GitHub webhooks.

## Files Overview

- `webhook.php` - GitHub webhook handler for automatic deployments
- `env.example` - Environment configuration template
- `deploy.sh` - Manual deployment setup script
- `index.php` - Main router for the status page
- `access.php` - Proxy logic for forwarding requests to larapush.statuspage.io

## Quick Setup Guide

### 1. Server Setup

Run the deployment script on your server:

```bash
# Upload files to your server and navigate to the repository
cd /root/
git clone git@github.com:dhirajabrandzzy/larapush-status.git
cd larapush-status
chmod +x deploy.sh
sudo ./deploy.sh
```

### 2. Configure Environment Variables

After running the deploy script, configure your environment:

```bash
cd /home/status.larapush.com/public_html
cp env.example .env
nano .env
```

Update the `.env` file with your values:

```bash
# Generate a webhook secret
openssl rand -hex 20
```

Add the generated secret to your `.env` file:

```env
GITHUB_WEBHOOK_SECRET=your_generated_secret_here
```

Set proper permissions:

```bash
chmod 600 .env
chown www-data:www-data .env
```

### 3. GitHub Webhook Setup

1. Go to your GitHub repository: `https://github.com/dhirajabrandzzy/larapush-status/settings/hooks`

2. Click "Add webhook"

3. Configure the webhook:
   - **Payload URL**: `https://status.larapush.com/webhook.php`
   - **Content type**: `application/json`
   - **Secret**: Use the secret you generated in step 2
   - **Events**: Select "Just the push event"
   - **Active**: ✓ checked

4. Click "Add webhook"

### 4. Test Deployment

1. Make a small change to any file in your repository
2. Commit and push the changes:
   ```bash
   git add .
   git commit -m "Test deployment"
   git push origin main
   ```
3. Check the deployment log:
   ```bash
   tail -f /home/status.larapush.com/public_html/deployment.log
   ```

## How It Works

1. **Push Trigger**: When you push to the `main` branch, GitHub sends a webhook to your server
2. **Signature Verification**: The webhook handler verifies the request is authentic using HMAC-SHA256
3. **Git Pull**: Automatically pulls the latest changes from GitHub
4. **Cache Clearing**: Clears any proxy cache to ensure fresh content
5. **Logging**: All activities are logged to `deployment.log`

## Webhook Handler Features

The `webhook.php` file includes:

- ✅ GitHub signature verification for security
- ✅ Automatic git pull from the repository
- ✅ Proxy cache clearing for instant updates
- ✅ Comprehensive logging of all activities
- ✅ Immediate HTTP 200 response to GitHub
- ✅ Background processing to prevent timeouts

## Configuration Options

In `webhook.php`, you can disable/enable various features:

```php
$enableGithubAuthentication = true;  // GitHub signature verification
$enableGitPull = true;               // Automatic git pull
$enableCacheClear = true;            // Clear proxy cache
$logDeployments = true;              // Log deployment activities
```

## Manual Deployments

If you need to manually deploy:

```bash
cd /home/status.larapush.com/public_html
git pull origin main
# Clear cache manually if needed
rm -rf cache/*
```

## Troubleshooting

### Check deployment logs:
```bash
tail -f /home/status.larapush.com/public_html/deployment.log
```

### Verify webhook configuration:
```bash
cd /home/status.larapush.com/public_html
php -f webhook.php
```

### Test git access:
```bash
cd /home/status.larapush.com/public_html
sudo -u www-data git pull origin main
```

### Check file permissions:
```bash
ls -la /home/status.larapush.com/public_html/
```

## Security Notes

- The `.env` file contains sensitive information and should have restricted permissions (`600`)
- The webhook secret should be cryptographically secure
- GitHub signature verification prevents unauthorized deployments
- The SSH deploy key should only have repository read access

## File Structure After Setup

```
/home/status.larapush.com/public_html/
├── access.php          # Proxy logic
├── index.php           # Main router
├── webhook.php         # GitHub webhook handler
├── .env               # Configuration (restricted permissions)
├── env.example        # Configuration template
├── deployment.log     # Deployment activity log
├── cache/            # Proxy cache directory
└── logs/             # Additional logs
```

## Next Steps

1. Verify the setup works by pushing a test change
2. Monitor the deployment logs to ensure everything is working
3. Set up monitoring or alerts for failed deployments if needed

For any issues, check the deployment logs first - they contain detailed information about what's happening during each deployment.
