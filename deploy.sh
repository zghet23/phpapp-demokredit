#!/usr/bin/env bash
# Full Azure deployment for kredit-plus PHP APM demo.
#
# Usage:
#   export DD_API_KEY=<your-datadog-api-key>
#   export DD_SITE=us3.datadoghq.com        # optional, defaults below
#   ./deploy.sh
#
# Prerequisites:
#   az login
#   Docker running (colima start or Docker Desktop)

set -euo pipefail

# ── Config ────────────────────────────────────────────────────────────────────
RESOURCE_GROUP="${RESOURCE_GROUP:-App-ServiceGroup-demo}"
LOCATION="${LOCATION:-canadacentral}"
ACR_NAME="${ACR_NAME:-kreditplusacr}"
APP_PLAN="${APP_PLAN:-ondemandusing}"
APP_NAME="${APP_NAME:-kredit-demo}"
IMAGE_NAME="kredit-plus-php"
IMAGE_TAG="${IMAGE_TAG:-latest}"
DD_SITE="${DD_SITE:-us3.datadoghq.com}"
DD_SERVICE="${DD_SERVICE:-kredit-plus}"
DD_ENV="${DD_ENV:-prd}"
DD_VERSION="${DD_VERSION:-1.0.0}"

SUBSCRIPTION=$(az account show --query id --output tsv 2>/dev/null)

: "${DD_API_KEY:?ERROR: export DD_API_KEY=<your-key> before running this script}"

FULL_IMAGE="${ACR_NAME}.azurecr.io/${IMAGE_NAME}:${IMAGE_TAG}"

echo "╔══════════════════════════════════════════════╗"
echo "║   Kredit Plus — Azure Deploy                 ║"
echo "╚══════════════════════════════════════════════╝"
echo ""
echo "  Resource Group : $RESOURCE_GROUP"
echo "  Location       : $LOCATION"
echo "  ACR            : $FULL_IMAGE"
echo "  App Name       : $APP_NAME"
echo "  DD Service     : $DD_SERVICE ($DD_ENV)"
echo ""

# ── 1. Resource Group ─────────────────────────────────────────────────────────
echo "▸ 1/7  Resource Group"
if az group show --name "$RESOURCE_GROUP" --output none 2>/dev/null; then
  echo "       already exists — skipping"
else
  az group create --name "$RESOURCE_GROUP" --location "$LOCATION" --output none
  echo "       created"
fi

# ── 2. ACR ────────────────────────────────────────────────────────────────────
echo "▸ 2/7  Azure Container Registry"
if az acr show --name "$ACR_NAME" --resource-group "$RESOURCE_GROUP" --output none 2>/dev/null; then
  echo "       already exists — skipping"
else
  az acr create --name "$ACR_NAME" --resource-group "$RESOURCE_GROUP" \
    --sku Basic --location "$LOCATION" --output none
  echo "       created"
fi
az acr update --name "$ACR_NAME" --admin-enabled true --output none
az acr login --name "$ACR_NAME"

# ── 3. Build & push ───────────────────────────────────────────────────────────
echo "▸ 3/7  Docker build (linux/amd64)"
docker build --platform linux/amd64 -t "$FULL_IMAGE" "$(dirname "$0")"

echo "▸ 4/7  Push to ACR"
docker push "$FULL_IMAGE"

# ── 4. App Service Plan ───────────────────────────────────────────────────────
echo "▸ 5/7  App Service Plan"
if az appservice plan show --name "$APP_PLAN" --resource-group "$RESOURCE_GROUP" --output none 2>/dev/null; then
  echo "       already exists — skipping"
else
  az appservice plan create \
    --name "$APP_PLAN" \
    --resource-group "$RESOURCE_GROUP" \
    --location "$LOCATION" \
    --is-linux \
    --sku B1 \
    --output none
  echo "       created"
fi

# ── 5. Web App ────────────────────────────────────────────────────────────────
echo "▸ 6/7  Web App"
ACR_USER=$(az acr credential show --name "$ACR_NAME" --query username --output tsv)
ACR_PASS=$(az acr credential show --name "$ACR_NAME" --query "passwords[0].value" --output tsv)

if az webapp show --name "$APP_NAME" --resource-group "$RESOURCE_GROUP" --output none 2>/dev/null; then
  echo "       already exists — updating image"
else
  az webapp create \
    --name "$APP_NAME" \
    --resource-group "$RESOURCE_GROUP" \
    --plan "$APP_PLAN" \
    --deployment-container-image-name "$FULL_IMAGE" \
    --output none
  echo "       created"
fi

# Ensure linuxFxVersion is set to sitecontainers mode
az webapp config set \
  --name "$APP_NAME" \
  --resource-group "$RESOURCE_GROUP" \
  --linux-fx-version "sitecontainers" \
  --output none

# ── 6. Containers ─────────────────────────────────────────────────────────────
echo "▸ 7/7  Configuring containers + environment"

# Main app container
az rest --method PUT \
  --url "https://management.azure.com/subscriptions/${SUBSCRIPTION}/resourceGroups/${RESOURCE_GROUP}/providers/Microsoft.Web/sites/${APP_NAME}/sitecontainers/main?api-version=2024-04-01" \
  --body "{
    \"location\": \"${LOCATION^}\",
    \"properties\": {
      \"image\": \"${FULL_IMAGE}\",
      \"isMain\": true,
      \"authType\": \"UserCredentials\",
      \"userName\": \"${ACR_USER}\",
      \"passwordSecret\": \"${ACR_PASS}\",
      \"targetPort\": \"80\",
      \"startUpCommand\": \"\"
    }
  }" --output none

# Datadog sidecar
az rest --method PUT \
  --url "https://management.azure.com/subscriptions/${SUBSCRIPTION}/resourceGroups/${RESOURCE_GROUP}/providers/Microsoft.Web/sites/${APP_NAME}/sitecontainers/ddagent?api-version=2024-04-01" \
  --body '{
    "location": "Canada Central",
    "properties": {
      "image": "docker.io/datadog/serverless-init:latest",
      "isMain": false,
      "authType": "Anonymous",
      "startUpCommand": ""
    }
  }' --output none

# App settings (Datadog + Unified Service Tagging)
az webapp config appsettings set \
  --name "$APP_NAME" \
  --resource-group "$RESOURCE_GROUP" \
  --settings \
    DD_API_KEY="$DD_API_KEY" \
    DD_SITE="$DD_SITE" \
    DD_SERVICE="$DD_SERVICE" \
    DD_ENV="$DD_ENV" \
    DD_VERSION="$DD_VERSION" \
    DD_TRACE_ENABLED="true" \
    DD_LOGS_INJECTION="true" \
    DD_TRACE_AGENT_URL="http://localhost:8126" \
    WEBSITES_ENABLE_APP_SERVICE_STORAGE="true" \
  --output none

# ── Done ──────────────────────────────────────────────────────────────────────
APP_URL=$(az webapp show --name "$APP_NAME" --resource-group "$RESOURCE_GROUP" \
  --query defaultHostName --output tsv)

echo ""
echo "✓ Deploy complete"
echo ""
echo "  URL     : https://${APP_URL}"
echo "  Health  : https://${APP_URL}/health"
echo "  UI      : https://${APP_URL}/"
echo ""
echo "  Restarting app..."
az webapp restart --name "$APP_NAME" --resource-group "$RESOURCE_GROUP" --output none
echo "  Done — allow ~60s for containers to start."
