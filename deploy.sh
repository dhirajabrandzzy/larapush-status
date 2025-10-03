#!/bin/bash

# Deployment script for status.larapush.com
# This script should be run on your server to set up CI/CD

set -e

echo "=== Larapush Status CI/CD Setup ==="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    print_error "Please run this script as root (use sudo)"
    exit 1
fi

# Set deployment directory
DEPLOY_DIR="/home/status.larapush.com/public_html"
REPO_URL="git@github.com:dhirajabrandzzy/larapush-status.git"

print_status "Setting up deployment in: $DEPLOY_DIR"

# Create directory if it doesn't exist
if [ ! -d "$DEPLOY_DIR" ]; then
    print_status "Creating deployment directory..."
    mkdir -p "$DEPLOY_DIR"
fi

# Navigate to deployment directory
cd "$DEPLOY_DIR"

# Check if git repository exists
if [ ! -d ".git" ]; then
    print_status "Initializing git repository..."
    
      # Set up git config for deployment
    git config --global user.email "deploy@larapush.com"
    git config --global user.name "Larapush Deploy"
    
    # Clone the repository
    print_status "Cloning repository from GitHub..."
    git clone "$REPO_URL" .
else
    print_status "Git repository already exists, pulling latest changes..."
    git pull origin main
fi

# Set up SSH key for git operations
print_status "Setting up SSH key for git operations..."
if [ ! -f "/root/.ssh/larapush_deploy_key" ]; then
    print_warning "SSH deploy key not found at /root/.ssh/larapush_deploy_key"
    print_warning "Make sure you've generated and added the deploy key to GitHub"
fi

# Set up SSH config for git operations
if [ ! -f "/root/.ssh/config" ]; then
    cat > /root/.ssh/config << EOF
Host github.com
    HostName github.com
    User git
    IdentityFile /root/.ssh/larapush_deploy_key
    IdentitiesOnly yes
EOF
fi

# Test SSH connection to GitHub
print_status "Testing SSH connection to GitHub..."
if ssh -T git@github.com 2>&1 | grep -q "successfully authenticated"; then
    print_status "SSH connection to GitHub successful"
else
    print_warning "SSH connection test failed. Check your deploy key setup."
fi

# Set proper permissions
print_status "Setting proper file permissions..."
chown -R www-data:www-data "$DEPLOY_DIR"
chmod -R 755 "$DEPLOY_DIR"

# Set special permissions for webhook
if [ -f "$DEPLOY_DIR/webhook.php" ]; then
    chmod 644 "$DEPLOY_DIR/webhook.php"
fi

# Create logs directory and set permissions
mkdir -p "$DEPLOY_DIR/logs"
chown -R www-data:www-data "$DEPLOY_DIR/logs"
chmod 755 "$DEPLOY_DIR/logs"

print_status "Installation complete!"

echo ""
echo "Next steps:"
echo "1. Copy env.example to .env and configure:"
echo "   cp $DEPLOY_DIR/env.example $DEPLOY_DIR/.env"
echo "   nano $DEPLOY_DIR/.env"
echo ""
echo "2. Set proper permissions for .env file:"
echo "   chmod 600 $DEPLOY_DIR/.env"
echo "   chown www-data:www-data $DEPLOY_DIR/.env"
echo ""
echo "3. Generate a webhook secret key and add it to .env:"
echo "   openssl rand -hex 20"
echo ""
echo "4. Set up GitHub webhook:"
echo "   - Go to: https://github.com/dhirajabrandzzy/larapush-status/settings/hooks"
echo "   - Add webhook URL: https://status.larapush.com/webhook.php"
echo "   - Set content type: application/json"
echo "   - Select 'Push events'"
echo "   - Use the secret from step 3"
echo ""
echo "=== Setup Complete ==="
