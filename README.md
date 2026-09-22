# Sunstreaker

Sunstreaker enables per-product Name + Number personalization for WooCommerce products (e.g., back-of-shirt prints).

## Per-product settings
WooCommerce → Edit Product → Product Data → General:
- **Use with Sunstreaker**
- **Sunstreaker price add-on** (default 5.00)

## Frontend behavior
If enabled for the product:
- Requires **Name** (max 20 chars)
- Requires **Number** (two digits 00–99)
- Shows a live jersey preview on the product image using per-product Name/Number boundaries
- When logged in with product edit permissions, shows an **Adjust Boundaries** button on the product page so the Name and Number boxes can be dragged/resized and saved

## Order storage
Name/Number are stored on the order line item meta so they appear in admin and emails.

## Updates
Automatic updates are pulled from GitHub Releases for `emkowale/sunstreaker`.

`./release.sh patch` only makes WordPress updates available after GitHub exposes a matching `vX.Y.Z` release asset named `sunstreaker-vX.Y.Z.zip`.
