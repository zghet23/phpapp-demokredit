#!/usr/bin/env bash
# Build the kredit-plus PHP image and push it to Azure Container Registry.
#
# Usage:
#   export ACR_NAME=<your-acr-name>          # e.g. kreditplusacr
#   export IMAGE_TAG=1.0.0                   # optional, defaults to "latest"
#   ./build-push.sh
#
# Prerequisites:
#   az login
#   az acr login --name $ACR_NAME

set -euo pipefail

ACR_NAME="${ACR_NAME:?Set ACR_NAME to your Azure Container Registry name}"
IMAGE_NAME="kredit-plus-php"
IMAGE_TAG="${IMAGE_TAG:-latest}"

FULL_IMAGE="${ACR_NAME}.azurecr.io/${IMAGE_NAME}:${IMAGE_TAG}"

echo "==> Logging in to ACR: ${ACR_NAME}"
az acr login --name "${ACR_NAME}"

echo "==> Building image: ${FULL_IMAGE}"
docker build \
  --platform linux/amd64 \
  -t "${FULL_IMAGE}" \
  .

echo "==> Pushing image: ${FULL_IMAGE}"
docker push "${FULL_IMAGE}"

echo ""
echo "Done. Image available at:"
echo "  ${FULL_IMAGE}"
echo ""
echo "Next steps in Azure Portal:"
echo "  1. Web App > Deployment Center > Docker Container > ${FULL_IMAGE}"
echo "  2. Web App > Deployment Center > Add sidecar:"
echo "       Image: datadog/serverless-init:latest"
echo "  3. Web App > Configuration > Application settings:"
echo "       DD_API_KEY        = <your-api-key>"
echo "       DD_SITE           = us3.datadoghq.com"
echo "       DD_SERVICE        = kredit-plus"
echo "       DD_ENV            = prd"
echo "       DD_VERSION        = ${IMAGE_TAG}"
echo "       DD_TRACE_ENABLED  = true"
echo "       DD_LOGS_INJECTION = true"
echo "       DD_TRACE_AGENT_URL = http://localhost:8126"
