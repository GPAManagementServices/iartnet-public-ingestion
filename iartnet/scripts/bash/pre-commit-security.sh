#!/bin/bash
# Pre-commit Security Scan with Trivy
# Usage: ./scripts/bash/pre-commit-security.sh
# This script should be run before committing to check for security vulnerabilities

set -e

echo "Running pre-commit security scan with Trivy..."

# Check if Trivy is installed
if ! command -v trivy &>/dev/null; then
	echo "WARNING: Trivy is not installed. Installing..."

	# Try different package managers
	if command -v brew &>/dev/null; then
		echo "Installing Trivy via Homebrew..."
		brew install trivy
	elif command -v apt-get &>/dev/null; then
		echo "Installing Trivy via apt..."
		sudo apt-get update
		sudo apt-get install -y wget apt-transport-https gnupg lsb-release
		wget -qO - https://aquasecurity.github.io/trivy-repo/deb/public.key | sudo apt-key add -
		echo "deb https://aquasecurity.github.io/trivy-repo/deb $(lsb_release -sc) main" | sudo tee -a /etc/apt/sources.list.d/trivy.list
		sudo apt-get update
		sudo apt-get install -y trivy
	elif command -v yum &>/dev/null; then
		echo "Installing Trivy via yum..."
		sudo yum install -y wget
		wget -qO - https://aquasecurity.github.io/trivy-repo/rpm/public.key | sudo rpm --import -
		sudo yum install -y https://aquasecurity.github.io/trivy-repo/rpm/releases/aquasecurity-trivy-repo.rpm
		sudo yum install -y trivy
	else
		echo "ERROR: Trivy is not installed and no package manager found."
		echo "Please install Trivy manually from: https://github.com/aquasecurity/trivy/releases"
		exit 1
	fi

	# Verify installation
	if ! command -v trivy &>/dev/null; then
		echo "ERROR: Trivy installation failed. Please install manually."
		exit 1
	fi
fi

echo "OK: Trivy is available"

# Scan filesystem for vulnerabilities
echo ""
echo "Scanning filesystem for vulnerabilities..."
FS_EXIT_CODE=0
trivy fs --severity CRITICAL,HIGH --exit-code 1 --no-progress . || FS_EXIT_CODE=$?

# Scan repository for vulnerabilities
echo ""
echo "Scanning repository for vulnerabilities..."
REPO_EXIT_CODE=0
trivy repo --severity CRITICAL,HIGH --exit-code 1 --no-progress . || REPO_EXIT_CODE=$?

# Scan Dockerfiles if they exist
if [ -d "infra/docker" ]; then
	echo ""
	echo "Scanning Dockerfiles for vulnerabilities..."
	find infra/docker -name "Dockerfile*" -type f | while read -r dockerfile; do
		echo "  Scanning: $dockerfile"
		trivy fs --severity CRITICAL,HIGH --exit-code 1 --no-progress "$(dirname "$dockerfile")" || true
	done
fi

# Summary
echo ""
echo "Security Scan Summary:"
if [ $FS_EXIT_CODE -eq 0 ] && [ $REPO_EXIT_CODE -eq 0 ]; then
	echo "OK: No CRITICAL or HIGH severity vulnerabilities found!"
	echo "You can proceed with your commit."
	exit 0
else
	echo "ERROR: CRITICAL or HIGH severity vulnerabilities detected!"
	echo "Please review the output above and fix vulnerabilities before committing."
	echo ""
	echo "To see full details, run:"
	echo "  trivy fs --severity CRITICAL,HIGH ."
	exit 1
fi
