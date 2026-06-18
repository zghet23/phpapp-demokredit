#!/usr/bin/env bash
# Delete all Azure resources for the kredit-plus APM demo.
# The source code stays in git — redeploy anytime with ./deploy.sh
#
# Usage:
#   ./teardown.sh                     # deletes app + ACR, keeps resource group
#   DELETE_RG=true ./teardown.sh      # also deletes the resource group

set -euo pipefail

RESOURCE_GROUP="${RESOURCE_GROUP:-App-ServiceGroup-demo}"
ACR_NAME="${ACR_NAME:-kreditplusacr}"
APP_NAME="${APP_NAME:-kredit-demo}"
APP_PLAN="${APP_PLAN:-ondemandusing}"
DELETE_RG="${DELETE_RG:-false}"

echo "╔══════════════════════════════════════════════╗"
echo "║   Kredit Plus — Azure Teardown               ║"
echo "╚══════════════════════════════════════════════╝"
echo ""
echo "  Resource Group : $RESOURCE_GROUP"
echo "  Web App        : $APP_NAME"
echo "  App Plan       : $APP_PLAN"
echo "  ACR            : $ACR_NAME"
echo "  Delete RG      : $DELETE_RG"
echo ""
read -rp "  Proceed? (y/N) " confirm
confirm=$(echo "$confirm" | tr '[:upper:]' '[:lower:]')
[[ "$confirm" == "y" ]] || { echo "Aborted."; exit 0; }
echo ""

# ── Web App ───────────────────────────────────────────────────────────────────
echo "▸ Deleting Web App: $APP_NAME"
if az webapp show --name "$APP_NAME" --resource-group "$RESOURCE_GROUP" --output none 2>/dev/null; then
  az webapp delete --name "$APP_NAME" --resource-group "$RESOURCE_GROUP" --output none
  echo "  deleted"
else
  echo "  not found — skipping"
fi

# ── App Service Plan ──────────────────────────────────────────────────────────
echo "▸ Deleting App Service Plan: $APP_PLAN"
if az appservice plan show --name "$APP_PLAN" --resource-group "$RESOURCE_GROUP" --output none 2>/dev/null; then
  az appservice plan delete --name "$APP_PLAN" --resource-group "$RESOURCE_GROUP" --yes --output none
  echo "  deleted"
else
  echo "  not found — skipping"
fi

# ── ACR ───────────────────────────────────────────────────────────────────────
echo "▸ Deleting ACR: $ACR_NAME"
if az acr show --name "$ACR_NAME" --resource-group "$RESOURCE_GROUP" --output none 2>/dev/null; then
  az acr delete --name "$ACR_NAME" --resource-group "$RESOURCE_GROUP" --yes --output none
  echo "  deleted"
else
  echo "  not found — skipping"
fi

# ── Resource Group (optional) ─────────────────────────────────────────────────
if [[ "$DELETE_RG" == "true" ]]; then
  echo "▸ Deleting Resource Group: $RESOURCE_GROUP"
  if az group show --name "$RESOURCE_GROUP" --output none 2>/dev/null; then
    az group delete --name "$RESOURCE_GROUP" --yes --no-wait --output none
    echo "  deletion queued (runs in background)"
  else
    echo "  not found — skipping"
  fi
fi

echo ""
echo "✓ Teardown complete."
echo ""
echo "  To redeploy:"
echo "    az login"
echo "    export DD_API_KEY=<your-key>"
echo "    ./deploy.sh"
